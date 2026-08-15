<?php

namespace App\Http\Controllers;

use App\Events\OrdenCocinaActualizadaEvent;
use App\Models\EstacionTrabajo;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\OrdenDetalleEstacion;
use App\Services\KdsEstacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CocinaController extends Controller
{
    public function pedidos(Request $request, KdsEstacionService $kds)
    {
        $fecha = $request->input('fecha', now()->toDateString());
        $estacion = $this->resolverEstacion($request);
        $activos = ['pendiente', 'en_preparacion', 'listo_para_recoger'];

        $detalles = OrdenDetalle::with(['opciones.modificadorOpcion.modificador'])
            ->whereHas('orden', fn ($query) => $query->whereDate('created_at', $fecha)
                ->whereIn('estado', ['pendiente', 'preparando', 'listo']))
            ->get();
        $kds->sincronizar($detalles);

        $ordenes = Orden::with([
            'cliente:id,nombre', 'mesa:id,numero', 'detalles.producto.categoria',
            'detalles.estacion', 'detalles.estadosEstacion.estacion:id,nombre,codigo',
            'detalles.opciones.modificadorOpcion.modificador:id,nombre,estacion_id',
        ])->whereDate('created_at', $fecha)
            ->whereIn('estado', ['pendiente', 'preparando', 'listo'])
            ->whereHas('detalles.estadosEstacion', fn ($query) => $query
                ->where('estacion_id', $estacion->id)->whereIn('estado', $activos))
            ->orderBy('created_at')->get()
            ->map(fn (Orden $orden) => $this->proyectarOrden($orden, $estacion->id, $activos))
            ->filter(fn (array $orden) => count($orden['detalles']) > 0)->values();

        return response()->json([
            'ordenes' => $ordenes,
            'estacion' => $estacion->only(['id', 'nombre', 'codigo']),
            'estaciones_disponibles' => $this->estacionesDisponibles(),
        ]);
    }

    public function actualizarDetalle(Request $request, OrdenDetalle $detalle, KdsEstacionService $kds)
    {
        $data = $request->validate([
            'estacion_id' => ['required', 'integer', 'exists:estaciones_trabajo,id'],
            'estado_cocina' => ['required', 'in:pendiente,en_preparacion,listo_para_recoger,recogido,servido'],
        ]);
        $estacion = $this->resolverEstacion($request, (int) $data['estacion_id']);

        $resultado = DB::transaction(function () use ($detalle, $data, $estacion, $kds) {
            $detalle = OrdenDetalle::lockForUpdate()->findOrFail($detalle->id);
            $kds->sincronizarDetalle($detalle);
            $estado = OrdenDetalleEstacion::where('orden_detalle_id', $detalle->id)
                ->where('estacion_id', $estacion->id)->lockForUpdate()->firstOrFail();
            $estado->update([
                'estado' => $data['estado_cocina'],
                'fecha_servido' => $data['estado_cocina'] === 'servido' ? now() : null,
            ]);

            $estados = $detalle->estadosEstacion()->pluck('estado');
            $completo = $estados->isNotEmpty() && $estados->every(fn ($valor) => $valor === 'servido');
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

        event(new OrdenCocinaActualizadaEvent($resultado['orden']));
        unset($resultado['orden']);
        return response()->json($resultado);
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
            $detalleData = $detalle->toArray();
            $detalleData['estado_cocina'] = $estado->estado;
            $detalleData['estado_estacion_id'] = $estado->id;
            $detalleData['incluye_producto'] = (int) $detalle->estacion_id === $estacionId;
            $detalleData['opciones'] = $detalle->opciones
                ->filter(fn ($opcion) => (int) ($opcion->modificadorOpcion?->modificador?->estacion_id ?? 0) === $estacionId)
                ->values()->toArray();
            return $detalleData;
        })->filter()->values()->all();
        return $data;
    }
}
