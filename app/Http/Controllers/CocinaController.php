<?php

namespace App\Http\Controllers;

use App\Events\OrdenCocinaActualizadaEvent;
use App\Models\EstacionTrabajo;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\OrdenDetalleEstacion;
use App\Services\KdsEstacionService;
use App\Services\KdsAsignacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CocinaController extends Controller
{
    private const ESTADOS_LISTOS = ['listo_para_recoger', 'recogido', 'servido'];

    
    public function pedidos(
        Request $request,
        KdsAsignacionService $asignaciones,
        KdsEstacionService $kds,
    )
    {
        $fecha = $request->input('fecha', now()->toDateString());
        $estacion = $this->resolverEstacion($request);
        // Los servidos siguen disponibles en una sección compacta del KDS:
        // sirven para revisión y para corregir un toque accidental.
        $activos = ['pendiente', 'en_preparacion', 'listo_para_recoger', 'servido'];

        // Parrilla puede adelantar una preorden durante los últimos 30 minutos.
        // La orden sigue programada para Caja; solo se habilita su preparación.
        $inicioVentana = now();
        $finVentana = $inicioVentana->copy()->addMinutes(30);
        $preordenesTempranas = collect();
        if ($estacion->codigo === 'PARRILLA' && $fecha === $inicioVentana->toDateString()) {
            $preordenesTempranas = Orden::with([
                'cliente:id,nombre', 'mesa:id,numero', 'detalles.producto.categoria',
                'detalles.estacion', 'detalles.estadosEstacion.estacion:id,nombre,codigo',
                'detalles.opciones.modificadorOpcion.modificador:id,nombre,estacion_id',
            ])->where('tipo_flujo', 'preorden')->where('estado_preorden', 'programada')
                ->whereDate('fecha_programada', $fecha)
                // Si Caja aún no la activó y la hora ya pasó, Parrilla debe
                // seguir viéndola: es una preorden atrasada, no una que deba
                // volver a desaparecer del tablero.
                ->where('fecha_programada', '<=', $finVentana)
                ->orderBy('fecha_programada')->get();

        }

        $ordenes = Orden::with([
            'cliente:id,nombre', 'mesa:id,numero', 'detalles.producto.categoria',
            'detalles.estacion', 'detalles.estadosEstacion.estacion:id,nombre,codigo',
            'detalles.opciones.modificadorOpcion.modificador:id,nombre,estacion_id',
        ])->operativas()->deFechaOperativa($fecha)
            ->whereIn('estado', ['pendiente', 'preparando', 'listo'])
            ->where(function ($query) use ($estacion, $activos) {
                $query->whereHas('detalles.estadosEstacion', fn ($query) => $query
                    ->where('estacion_id', $estacion->id)->whereIn('estado', $activos))
                    ->orWhereHas('detalles.opciones.modificadorOpcion.modificador', fn ($query) => $query
                        ->where('estacion_id', $estacion->id));
            })
            ->orderBy('created_at')->get();

        // Repara estados secundarios faltantes en órdenes creadas antes de
        // que se sincronizaran las opciones por estación. Sin esto, un pescado
        // con guarniciones de Cocina solo aparece después de que Parrilla lo
        // marca listo, aunque debería verse bloqueado desde la activación.
        $ordenes->each(function (Orden $orden) use ($kds) {
            $kds->sincronizar($orden->detalles);
            $orden->detalles->each(fn (OrdenDetalle $detalle) =>
                $detalle->load('estadosEstacion.estacion:id,nombre,codigo')
            );
        });

        $ordenes = $ordenes
            ->map(fn (Orden $orden) => $this->proyectarOrden($orden, $estacion->id, $activos))
            ->filter(fn (array $orden) => count($orden['detalles']) > 0)
            ->values();

        $preordenesTempranas = $preordenesTempranas
            ->map(function (Orden $orden) use ($estacion, $activos) {
                $data = $this->proyectarOrden($orden, $estacion->id, $activos);
                $data['preorden_temprana'] = true;
                return $data;
            })
            ->filter(fn (array $orden) => count($orden['detalles']) > 0)
            ->values();
        $ordenes = $ordenes->concat($preordenesTempranas)->values();

        // Recalcular también durante la consulta hace que los indicadores se
        // recuperen aunque se haya perdido un evento del canal en tiempo real.
        $asignaciones->sincronizarAsignaciones($estacion->id);
        $porOrden = $asignaciones->asignacionesParaEstacion($estacion->id);
        $ordenes = $ordenes->map(function (array $orden) use ($porOrden) {
            $orden['asignacion'] = $porOrden[$orden['id']] ?? null;
            return $orden;
        })->values();

        // Una preorden activada siempre encabeza el tablero: al ser excepcional,
        // debe atenderse antes que cualquier orden normal, incluso si esta última
        // ya había comenzado a prepararse.
        $ordenes = $this->priorizarPreordenesActivadas($ordenes);

        // La posición de una ficha no cambia cuando Parrilla libera uno de sus
        // productos. Solo cambia el estado del producto dentro de la misma
        // ficha, evitando que el operador pierda de vista el resto del pedido.

        $preordenes = Orden::with([
            'cliente:id,nombre', 'mesa:id,numero', 'detalles.producto.categoria', 'detalles.estacion',
            'detalles.opciones.modificadorOpcion.modificador:id,nombre,estacion_id',
        ])->where('tipo_flujo', 'preorden')->where('estado_preorden', 'programada')
            ->whereDate('fecha_programada', $fecha)
            ->whereNotIn('id', $preordenesTempranas->pluck('id'))
            ->orderBy('fecha_programada')->get()
            ->map(fn (Orden $orden) => $this->proyectarPreorden($orden, $estacion->id))
            ->filter(fn (array $orden) => count($orden['detalles']) > 0)->values();

        return response()->json([
            'ordenes' => $ordenes,
            'preordenes_programadas' => $preordenes,
            'estacion' => $estacion->only(['id', 'nombre', 'codigo']),
            'estaciones_disponibles' => $this->estacionesDisponibles(),
        ]);
    }

    public function actualizarDetalle(Request $request, OrdenDetalle $detalle, KdsEstacionService $kds, KdsAsignacionService $asignaciones)
    {
        $data = $request->validate([
            'estacion_id' => ['required', 'integer', 'exists:estaciones_trabajo,id'],
            'estado_cocina' => ['required', 'in:pendiente,en_preparacion,listo_para_recoger,recogido,servido'],
        ]);
        $estacion = $this->resolverEstacion($request, (int) $data['estacion_id']);

        $detalle->loadMissing('orden');
        if ($detalle->orden?->esPreordenProgramada()) {
            abort_unless(
                $this->puedePrepararPreordenAnticipada($detalle->orden, $estacion),
                422,
                'La preorden solo puede prepararse en Parrilla durante los últimos 30 minutos.'
            );
        }

        $resultado = DB::transaction(function () use ($detalle, $data, $estacion, $kds) {
            $detalle = OrdenDetalle::lockForUpdate()->findOrFail($detalle->id);
            $kds->sincronizarDetalle($detalle);
            $estado = OrdenDetalleEstacion::where('orden_detalle_id', $detalle->id)
                ->where('estacion_id', $estacion->id)->lockForUpdate()->firstOrFail();

            if (
                $estacion->codigo === 'PARRILLA'
                && $estado->estado === 'servido'
                && $data['estado_cocina'] !== 'servido'
            ) {
                $cocinaYaTrabajo = OrdenDetalleEstacion::where('orden_detalle_id', $detalle->id)
                    ->where('estacion_id', '!=', $estacion->id)
                    ->whereNotIn('estado', ['pendiente'])
                    ->exists();

                abort_if($cocinaYaTrabajo, 422, 'No se puede revertir Parrilla porque Cocina ya trabajó este producto.');
            }

            $estado->update([
                'estado' => $data['estado_cocina'],
                'fecha_servido' => $data['estado_cocina'] === 'servido' ? now() : null,
            ]);

            $estados = $detalle->estadosEstacion()->pluck('estado');
            $completo = $estados->isNotEmpty() && $estados->every(fn ($valor) => in_array($valor, self::ESTADOS_LISTOS, true));
            $detalle->update([
                'estado_cocina' => $completo ? 'servido' : 'pendiente',
                'fecha_servido' => $completo ? now() : null,
            ]);

            $orden = Orden::lockForUpdate()->findOrFail($detalle->orden_id);
            $pendiente = OrdenDetalleEstacion::whereHas('detalle', fn ($query) => $query->where('orden_id', $orden->id))
                ->whereNotIn('estado', ['servido', 'recogido'])->exists();
            $orden->update(['estado' => $pendiente ? 'preparando' : 'listo']);

            return [
                'orden' => $orden->fresh(), 'detalle' => $detalle,
                'estado' => $estado->fresh('estacion:id,nombre,codigo'),
                'orden_estado' => $orden->estado, 'orden_id' => $orden->id,
            ];
        });

        $asignaciones->sincronizarAsignaciones($estacion->id);

        event(new OrdenCocinaActualizadaEvent($resultado['orden'], [], 'kds'));
        unset($resultado['orden']);
        return response()->json($resultado);
    }

    public function actualizarDetalles(Request $request, KdsEstacionService $kds, KdsAsignacionService $asignaciones)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct', 'exists:orden_detalles,id'],
            'estacion_id' => ['required', 'integer', 'exists:estaciones_trabajo,id'],
            'estado_cocina' => ['required', 'in:pendiente,servido'],
        ]);
        $estacion = $this->resolverEstacion($request, (int) $data['estacion_id']);

        $resultado = DB::transaction(function () use ($data, $estacion, $kds) {
            $detalles = OrdenDetalle::with('orden')->whereIn('id', $data['ids'])
                ->orderBy('id')->lockForUpdate()->get();

            foreach ($detalles as $detalle) {
                if ($detalle->orden?->esPreordenProgramada()) {
                    abort_unless(
                        $this->puedePrepararPreordenAnticipada($detalle->orden, $estacion),
                        422,
                        'La preorden solo puede prepararse en Parrilla durante los últimos 30 minutos.'
                    );
                }

                $kds->sincronizarDetalle($detalle);
                $estado = OrdenDetalleEstacion::where('orden_detalle_id', $detalle->id)
                    ->where('estacion_id', $estacion->id)->lockForUpdate()->firstOrFail();

                if (
                    $estacion->codigo === 'PARRILLA'
                    && $estado->estado === 'servido'
                    && $data['estado_cocina'] !== 'servido'
                ) {
                    $cocinaYaTrabajo = OrdenDetalleEstacion::where('orden_detalle_id', $detalle->id)
                        ->where('estacion_id', '!=', $estacion->id)
                        ->whereNotIn('estado', ['pendiente'])->exists();
                    abort_if($cocinaYaTrabajo, 422, 'No se puede revertir Parrilla porque Cocina ya trabajó este producto.');
                }

                $estado->update([
                    'estado' => $data['estado_cocina'],
                    'fecha_servido' => $data['estado_cocina'] === 'servido' ? now() : null,
                ]);

                $estados = $detalle->estadosEstacion()->pluck('estado');
                $completo = $estados->isNotEmpty() && $estados->every(fn ($valor) => in_array($valor, self::ESTADOS_LISTOS, true));
                $detalle->update([
                    'estado_cocina' => $completo ? 'servido' : 'pendiente',
                    'fecha_servido' => $completo ? now() : null,
                ]);
            }

            $ordenes = Orden::whereIn('id', $detalles->pluck('orden_id')->unique())
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($ordenes as $orden) {
                $pendiente = OrdenDetalleEstacion::whereHas('detalle', fn ($query) => $query->where('orden_id', $orden->id))
                    ->whereNotIn('estado', ['servido', 'recogido'])->exists();
                $orden->update(['estado' => $pendiente ? 'preparando' : 'listo']);
            }

            return [
                'detalle_ids' => $detalles->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'ordenes' => $ordenes->map(fn ($orden) => $orden->fresh())->values(),
            ];
        });

        $asignaciones->sincronizarAsignaciones($estacion->id);
        foreach ($resultado['ordenes'] as $orden) event(new OrdenCocinaActualizadaEvent($orden, [], 'kds'));

        return response()->json([
            'detalle_ids' => $resultado['detalle_ids'],
            'ordenes' => $resultado['ordenes']->map(fn ($orden) => [
                'orden_id' => (int) $orden->id,
                'orden_estado' => $orden->estado,
            ])->values(),
        ]);
    }

    public function registrarSesion(Request $request, KdsAsignacionService $asignaciones)
    {
        $data = $request->validate(['estacion_id' => ['required', 'integer', 'exists:estaciones_trabajo,id']]);
        $estacion = $this->resolverEstacion($request, (int) $data['estacion_id']);
        $usuario = auth('api')->user();
        abort_unless($usuario, 401);
        $resultado = $asignaciones->registrarSesion($usuario, $estacion->id);
        if ($resultado['cola_cambio']) event(new \App\Events\KdsColaActualizadaEvent());

        return response()->json(['sesion' => $resultado['sesion']->only(['id', 'color', 'ultima_actividad'])]);
    }

    public function preordenesProximas(Request $request)
    {
        $fecha = $request->input('fecha', now()->toDateString());
        $estacion = $this->resolverEstacion($request);
        if ($estacion->codigo !== 'PARRILLA' || $fecha !== now()->toDateString()) {
            return response()->json(['ids' => []]);
        }

        return response()->json([
            'ids' => Orden::where('tipo_flujo', 'preorden')->where('estado_preorden', 'programada')
                ->whereDate('fecha_programada', $fecha)
                ->where('fecha_programada', '<=', now()->addMinutes(30))
                ->orderBy('fecha_programada')->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ]);
    }

    private function resolverEstacion(Request $request, ?int $forzada = null): EstacionTrabajo
    {
        $usuario = auth('api')->user();
        $rol = mb_strtolower($usuario?->role?->nombre ?? '');
        $privilegiado = in_array($rol, ['admin', 'administrador', 'gerente'], true);
        $solicitada = $forzada ?? ($request->filled('estacion_id') ? (int) $request->input('estacion_id') : null);
        if (!$solicitada && $request->filled('estacion')) {
            $solicitada = EstacionTrabajo::whereRaw('LOWER(codigo) = ?', [mb_strtolower($request->input('estacion'))])
                ->value('id');
        }

        if (!$privilegiado) {
            abort_unless($usuario?->estacion_id, 403, 'El usuario no tiene una estación de trabajo asignada.');
            abort_if($solicitada && $solicitada !== (int) $usuario->estacion_id, 403, 'No tienes acceso a esa estación.');
            $solicitada = (int) $usuario->estacion_id;
        }

        $query = EstacionTrabajo::where('activa', true)->whereIn('codigo', ['COCINA', 'PARRILLA']);
        if ($solicitada) return $query->findOrFail($solicitada);
        return $query->orderByRaw("CASE WHEN codigo = 'COCINA' THEN 0 ELSE 1 END")->firstOrFail();
    }

    private function estacionesDisponibles()
    {
        $usuario = auth('api')->user();
        $rol = mb_strtolower($usuario?->role?->nombre ?? '');
        $privilegiado = in_array($rol, ['admin', 'administrador', 'gerente'], true);
        return EstacionTrabajo::where('activa', true)->whereIn('codigo', ['COCINA', 'PARRILLA'])
            ->when(!$privilegiado, fn ($query) => $query->whereKey($usuario?->estacion_id))
            ->orderBy('orden')->get(['id', 'nombre', 'codigo']);
    }

    private function proyectarOrden(Orden $orden, int $estacionId, array $activos): array
    {
        $data = $orden->toArray();
        $data['detalles'] = $orden->detalles->map(function (OrdenDetalle $detalle) use ($estacionId, $activos) {
            $estado = $detalle->estadosEstacion->firstWhere('estacion_id', $estacionId);
            if (!$estado || !in_array($estado->estado, $activos, true)) return null;
            $bloqueado = false;
            if ((int) $detalle->estacion_id !== $estacionId) {
                $estadoPrincipal = $detalle->estadosEstacion->firstWhere('estacion_id', (int) $detalle->estacion_id);
                $bloqueado = $estadoPrincipal && !in_array($estadoPrincipal->estado, self::ESTADOS_LISTOS, true);
            }
            $detalleData = $detalle->toArray();
            $detalleData['estado_cocina'] = $estado->estado;
            $detalleData['estado_estacion_id'] = $estado->id;
            $detalleData['incluye_producto'] = (int) $detalle->estacion_id === $estacionId;
            $detalleData['bloqueado'] = $bloqueado;
            $detalleData['listo_para_atender'] = !$bloqueado && (int) $detalle->estacion_id !== $estacionId;
            $detalleData['opciones'] = $detalle->opciones
                ->filter(fn ($opcion) => (int) ($opcion->modificadorOpcion?->modificador?->estacion_id ?? 0) === $estacionId)
                ->values()->toArray();
            return $detalleData;
        })->filter()->values()->all();
        return $data;
    }

    private function proyectarPreorden(Orden $orden, int $estacionId): array
    {
        return [
            'id' => $orden->id,
            'numero_orden' => $orden->numero_orden,
            'fecha_programada' => $orden->fecha_programada,
            'tipo_orden' => $orden->tipo_orden,
            'tipo_flujo' => $orden->tipo_flujo,
            'estado_preorden' => $orden->estado_preorden,
            'cliente' => $orden->cliente,
            'mesa' => $orden->mesa,
            'bloqueada' => true,
            'detalles' => $orden->detalles->map(function (OrdenDetalle $detalle) use ($estacionId) {
                $opciones = $detalle->opciones->filter(
                    fn ($opcion) => (int) ($opcion->modificadorOpcion?->modificador?->estacion_id ?? 0) === $estacionId
                )->values();
                if ((int) $detalle->estacion_id !== $estacionId && $opciones->isEmpty()) return null;
                $data = $detalle->toArray();
                $data['incluye_producto'] = (int) $detalle->estacion_id === $estacionId;
                $data['bloqueado'] = true;
                $data['opciones'] = $opciones->toArray();
                return $data;
            })->filter()->values()->all(),
        ];
    }

    private function puedePrepararPreordenAnticipada(Orden $orden, EstacionTrabajo $estacion): bool
    {
        if ($estacion->codigo !== 'PARRILLA' || !$orden->fecha_programada) return false;

        $ahora = now();
        return $orden->fecha_programada->lessThanOrEqualTo($ahora->copy()->addMinutes(30));
    }

    /** @param \Illuminate\Support\Collection<int, array> $ordenes */
    private function priorizarPreordenesActivadas(\Illuminate\Support\Collection $ordenes): \Illuminate\Support\Collection
    {
        $preordenes = $ordenes->filter(fn (array $orden) => ($orden['tipo_flujo'] ?? null) === 'preorden'
            && ($orden['estado_preorden'] ?? null) === 'activada')->values();
        if ($preordenes->isEmpty()) return $ordenes;

        $normales = $ordenes->reject(fn (array $orden) => ($orden['tipo_flujo'] ?? null) === 'preorden'
            && ($orden['estado_preorden'] ?? null) === 'activada')->values();
        return $preordenes->concat($normales)->values();
    }

    /** @param \Illuminate\Support\Collection<int, array> $ordenes */
    private function intercalarPasesListos(\Illuminate\Support\Collection $ordenes): \Illuminate\Support\Collection
    {
        // Un pase solo tiene prioridad mientras todavía requiere una acción de
        // Cocina. Un producto ya servido no debe seguir moviendo toda su ficha.
        $esPasePendiente = fn (array $detalle) => ($detalle['listo_para_atender'] ?? false) === true
            && ($detalle['estado_cocina'] ?? null) !== 'servido';

        $pases = $ordenes->filter(fn (array $orden) => collect($orden['detalles'])
            ->contains($esPasePendiente))
            ->values();
        $normales = $ordenes->reject(fn (array $orden) => collect($orden['detalles'])
            ->contains($esPasePendiente))
            ->values();

        if ($pases->isEmpty() || $normales->isEmpty()) return $ordenes;

        $resultado = collect();
        $indicePase = 0;
        foreach ($normales as $indice => $orden) {
            $resultado->push($orden);
            if ($indice >= 1 && $indicePase < $pases->count()) {
                $resultado->push($pases[$indicePase++]);
            }
        }

        while ($indicePase < $pases->count()) {
            $resultado->push($pases[$indicePase++]);
        }

        return $resultado->values();
    }

}
