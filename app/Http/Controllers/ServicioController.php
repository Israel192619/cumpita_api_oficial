<?php

namespace App\Http\Controllers;

use App\Events\OrdenCocinaActualizadaEvent;
use App\Events\ServicioSesionActualizadaEvent;
use App\Models\HistorialCambioOrden;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\OrdenDetalleEstacion;
use App\Services\KdsEstacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class ServicioController extends Controller
{
    private const ESTADOS_LISTOS = ['listo_para_recoger', 'recogido', 'servido'];

    public function index(Request $request, KdsEstacionService $kds)
    {
        $fecha = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
        ])['fecha'] ?? now()->toDateString();
        $usuario = $this->usuarioConAcceso();
        $esMesero = $this->esMesero($usuario);
        if ($this->esSesionServicio()) {
            $this->asegurarMesero($usuario);
        }
        $base = Orden::with([
            'mesa:id,numero', 'cliente:id,nombre', 'detalles.producto:id,nombre',
            'detalles.opciones.modificadorOpcion:id,nombre', 'detalles.estadosEstacion',
            'mesero:id,name',
        ])->operativas()->deFechaOperativa($fecha)
            ->whereNotIn('estado', ['entregado', 'cancelado'])->orderBy('created_at');

        $ordenes = (clone $base)->get();
        $kds->sincronizar($ordenes->pluck('detalles')->flatten());
        $ordenes->load('detalles.estadosEstacion');

        $preordenes = Orden::with([
            'mesa:id,numero', 'cliente:id,nombre', 'detalles.producto:id,nombre',
            'detalles.opciones.modificadorOpcion:id,nombre',
        ])->where('tipo_flujo', 'preorden')->where('estado_preorden', 'programada')
            ->whereDate('fecha_programada', $fecha)
            ->orderBy('fecha_programada')->get();

        return response()->json([
            'disponibles' => $ordenes->whereNull('mesero_id')->map(fn ($orden) => $this->ficha($orden))->values(),
            'mis_fichas' => $esMesero
                ? $ordenes->where('mesero_id', $usuario->id)->map(fn ($orden) => $this->ficha($orden))->values()
                : [],
            'preordenes_programadas' => $preordenes->map(fn ($orden) => $this->fichaPreorden($orden))->values(),
        ]);
    }

    public function tomar(Orden $orden)
    {
        $mesero = $this->meseroServicio();
        $orden = DB::transaction(function () use ($orden, $mesero) {
            $orden = Orden::lockForUpdate()->findOrFail($orden->id);
            $this->asegurarOrdenOperativa($orden);
            abort_if(in_array($orden->estado, ['entregado', 'cancelado'], true), 422, 'La ficha ya no está disponible.');
            abort_if($orden->mesero_id && $orden->mesero_id !== $mesero->id, 409, 'Otro mesero ya tomó esta ficha.');
            if (!$orden->mesero_id) $orden->update(['mesero_id' => $mesero->id, 'tomada_en' => now()]);
            return $orden;
        });
        $this->notificar($orden);
        return response()->json(['message' => 'Ficha tomada.', 'orden_id' => $orden->id]);
    }

    public function confirmarDetalle(OrdenDetalle $detalle, KdsEstacionService $kds)
    {
        $mesero = $this->meseroServicio();
        $orden = DB::transaction(function () use ($detalle, $mesero, $kds) {
            $detalle = OrdenDetalle::with('orden')->lockForUpdate()->findOrFail($detalle->id);
            $this->asegurarOrdenOperativa($detalle->orden);
            abort_unless($detalle->orden->mesero_id === $mesero->id, 403, 'Esta ficha no está asignada al usuario.');
            $kds->sincronizarDetalle($detalle);
            $detalle->estadosEstacion()->update(['estado' => 'servido', 'fecha_servido' => now()]);
            $detalle->update(['estado_cocina' => 'servido', 'fecha_servido' => now()]);
            return $detalle->orden;
        });
        $this->notificar($orden);
        return response()->json(['message' => 'Producto confirmado.', 'detalle_id' => $detalle->id]);
    }

    public function liberar(Orden $orden)
    {
        $mesero = $this->meseroServicio();
        $orden = DB::transaction(function () use ($orden, $mesero) {
            $orden = Orden::lockForUpdate()->findOrFail($orden->id);
            $this->asegurarOrdenOperativa($orden);
            abort_unless($orden->mesero_id === $mesero->id, 403, 'Esta ficha no está asignada al usuario.');
            abort_if(in_array($orden->estado, ['entregado', 'cancelado'], true), 422, 'La ficha ya no puede liberarse.');
            $this->registrarLiberacion($orden, $mesero->id, 'liberacion_manual');
            $orden->update(['mesero_id' => null, 'tomada_en' => null]);
            return $orden;
        });
        $this->notificar($orden);
        return response()->json(['message' => 'Ficha liberada.', 'orden_id' => $orden->id]);
    }

    public function cerrarSesion(Request $request)
    {
        $mesero = $this->meseroServicio();
        $payload = JWTAuth::getPayload();
        $ordenes = Orden::where('mesero_id', $mesero->id)
            ->whereNotIn('estado', ['entregado', 'cancelado'])->get();

        if ($ordenes->isNotEmpty() && !$request->boolean('liberar_fichas')) {
            return response()->json([
                'message' => 'Debes confirmar la liberación de tus fichas antes de cerrar la sesión.',
                'requiere_confirmacion' => true,
                'fichas_asignadas' => $ordenes->count(),
            ], 409);
        }

        if ($ordenes->isNotEmpty()) {
            $ordenes = DB::transaction(function () use ($mesero) {
                $bloqueadas = Orden::where('mesero_id', $mesero->id)
                    ->whereNotIn('estado', ['entregado', 'cancelado'])->lockForUpdate()->get();
                foreach ($bloqueadas as $orden) {
                    $this->registrarLiberacion($orden, $mesero->id, 'cierre_sesion');
                    $orden->update(['mesero_id' => null, 'tomada_en' => null]);
                }
                return $bloqueadas;
            });
        }

        $sessionId = (string) ($payload->get('session_id') ?: 'principal-'.$mesero->id);
        // Una sesión creada por PIN tiene su propio JWT y debe invalidarse.
        // El JWT principal del celular identifica al usuario en todo el sistema:
        // cerrar Servicio no debe cerrar esa autenticación general.
        if ($this->esSesionServicio()) {
            JWTAuth::invalidate(JWTAuth::getToken());
        }
        try {
            event(new ServicioSesionActualizadaEvent('sesion_cerrada', $mesero->id, $sessionId));
        } catch (\Throwable $e) {
            Log::warning('No se pudo notificar el cierre de Servicio.', ['user_id' => $mesero->id, 'error' => $e->getMessage()]);
        }
        foreach ($ordenes as $orden) $this->notificar($orden);

        return response()->json(['message' => 'Sesión cerrada y fichas liberadas.']);
    }

    public function entregar(Orden $orden)
    {
        $mesero = $this->meseroServicio();
        $orden = DB::transaction(function () use ($orden, $mesero) {
            $orden = Orden::with('detalles.estadosEstacion')->lockForUpdate()->findOrFail($orden->id);
            $this->asegurarOrdenOperativa($orden);
            abort_unless($orden->mesero_id === $mesero->id, 403, 'Esta ficha no está asignada al usuario.');
            $todosListos = $orden->detalles->isNotEmpty() && $orden->detalles->every(fn ($detalle) =>
                $detalle->estadosEstacion->isNotEmpty()
                && $detalle->estadosEstacion->every(fn ($estado) => in_array($estado->estado, self::ESTADOS_LISTOS, true))
            );
            abort_unless($todosListos, 422, 'Todos los productos deben estar listos antes de entregar.');
            $orden->update(['estado' => 'entregado', 'entregada_en' => now()]);
            return $orden;
        });
        $this->notificar($orden);
        return response()->json(['message' => 'Pedido entregado.', 'orden_id' => $orden->id]);
    }

    private function ficha(Orden $orden): array
    {
        $detalles = $orden->detalles->map(function ($detalle) {
            $listo = $detalle->estadosEstacion->isNotEmpty()
                && $detalle->estadosEstacion->every(fn ($estado) => in_array($estado->estado, self::ESTADOS_LISTOS, true));
            return [
                'id' => $detalle->id, 'cantidad' => $detalle->cantidad, 'producto' => $detalle->producto?->nombre,
                'nota' => $detalle->nota, 'listo' => $listo,
                'opciones' => $detalle->opciones->pluck('modificadorOpcion.nombre')->filter()->values(),
            ];
        })->values();
        return [
            'id' => $orden->id, 'numero_orden' => $orden->numero_orden, 'created_at' => $orden->created_at,
            'mesa' => $orden->mesa?->numero, 'cliente' => $orden->cliente?->nombre,
            'tipo_orden' => $orden->tipo_orden,
            'hora' => ($orden->fecha_orden ?? $orden->created_at)?->format('H:i'),
            'tiempo_espera_minutos' => (int) ($orden->fecha_orden ?? $orden->created_at)?->diffInMinutes(now()),
            'mesero' => $orden->mesero?->name, 'detalles' => $detalles,
            'listos' => $detalles->where('listo', true)->count(), 'total_items' => $detalles->count(),
            'todo_listo' => $detalles->isNotEmpty() && $detalles->every(fn ($detalle) => $detalle['listo']),
        ];
    }

    private function fichaPreorden(Orden $orden): array
    {
        return [
            'id' => $orden->id,
            'numero_orden' => $orden->numero_orden,
            'mesa' => $orden->mesa?->numero,
            'cliente' => $orden->cliente?->nombre,
            'tipo_orden' => $orden->tipo_orden,
            'fecha_programada' => $orden->fecha_programada,
            'estado_preorden' => $orden->estado_preorden,
            'detalles' => $orden->detalles->map(fn ($detalle) => [
                'id' => $detalle->id,
                'cantidad' => $detalle->cantidad,
                'producto' => $detalle->producto?->nombre,
                'nota' => $detalle->nota,
                'opciones' => $detalle->opciones->pluck('modificadorOpcion.nombre')->filter()->values(),
                'listo' => false,
            ])->values(),
            'total_items' => $orden->detalles->count(),
            'bloqueada' => true,
        ];
    }

    private function asegurarOrdenOperativa(Orden $orden): void
    {
        abort_if($orden->esPreordenProgramada(), 422, 'La preorden está pendiente de activación y no puede procesarse.');
    }

    private function registrarLiberacion(Orden $orden, int $meseroId, string $motivo): void
    {
        HistorialCambioOrden::create([
            'orden_id' => $orden->id,
            'user_id' => $meseroId,
            'tipo_cambio' => 'estado_cambiado',
            'datos_anterior' => ['mesero_id' => $meseroId, 'tomada_en' => $orden->tomada_en, 'accion' => $motivo],
            'datos_nuevo' => ['mesero_id' => null, 'tomada_en' => null, 'accion' => 'ficha_liberada'],
        ]);
    }

    private function usuarioConAcceso()
    {
        $user = auth('api')->user();
        $rol = mb_strtolower($user?->role?->nombre ?? '');
        abort_unless(in_array($rol, ['mesero', 'despacho', 'admin', 'administrador', 'gerente'], true), 403, 'No tienes acceso a Servicio.');
        return $user;
    }

    private function meseroServicio()
    {
        $user = auth('api')->user();
        $this->asegurarMesero($user);
        return $user;
    }

    private function asegurarMesero($user): void
    {
        abort_unless(mb_strtolower($user?->role?->nombre ?? '') === 'mesero', 403, 'La sesión no pertenece a un mesero.');
    }

    private function esMesero($user): bool
    {
        return mb_strtolower($user?->role?->nombre ?? '') === 'mesero';
    }

    private function esSesionServicio(): bool
    {
        try {
            return \Tymon\JWTAuth\Facades\JWTAuth::getPayload()->get('scope') === 'servicio';
        } catch (\Throwable) {
            return false;
        }
    }

    private function notificar(Orden $orden): void
    {
        try { event(new OrdenCocinaActualizadaEvent($orden)); }
        catch (\Throwable $e) { Log::warning('No se pudo notificar el cambio de servicio.', ['orden_id' => $orden->id, 'error' => $e->getMessage()]); }
    }
}
