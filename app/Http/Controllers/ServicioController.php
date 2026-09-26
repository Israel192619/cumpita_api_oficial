<?php

namespace App\Http\Controllers;

use App\Events\OrdenCocinaActualizadaEvent;
use App\Events\ServicioSesionActualizadaEvent;
use App\Events\ServicioFichaActualizadaEvent;
use App\Models\HistorialCambioOrden;
use App\Models\Cliente;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\OrdenDetalleEstacion;
use App\Services\KdsEstacionService;
use App\Services\ServicioColaboracionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class ServicioController extends Controller
{
    private const ESTADOS_LISTOS = ['listo_para_recoger', 'recogido', 'servido'];
    private const MAX_FICHAS_POR_MESERO = 2;

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
            'mesa:id,numero', 'cliente', 'detalles.producto:id,nombre,categoria_id', 'detalles.producto.categoria:id,nombre,parent_id', 'detalles.producto.categoria.parent:id,nombre',
            'detalles.opciones.modificadorOpcion:id,nombre,modificador_id', 'detalles.opciones.modificadorOpcion.modificador:id,color_fondo,estacion_id', 'detalles.estadosEstacion', 'detalles.historialCambios.user:id,name',
            'mesero:id,name',
            'pagos:id,id_orden,monto_pagado', 'deliveryCambioPreparadoPor:id,name',
        ])->operativas()->deFechaOperativa($fecha)
            ->whereNotIn('estado', ['entregado', 'cancelado'])
            ->orderByRaw("CASE WHEN tipo_flujo = 'preorden' AND estado_preorden = 'activada' THEN 0 ELSE 1 END")
            ->orderBy('created_at');

        $ordenes = (clone $base)->get();
        $kds->sincronizar($ordenes->pluck('detalles')->flatten());
        $ordenes->load('detalles.estadosEstacion');

        $entregadas = Orden::with([
                'mesa:id,numero', 'cliente', 'detalles.producto:id,nombre,categoria_id', 'detalles.producto.categoria:id,nombre,parent_id', 'detalles.producto.categoria.parent:id,nombre',
                'detalles.opciones.modificadorOpcion:id,nombre,modificador_id', 'detalles.opciones.modificadorOpcion.modificador:id,color_fondo,estacion_id', 'detalles.estadosEstacion', 'detalles.historialCambios.user:id,name',
                'mesero:id,name',
                'pagos:id,id_orden,monto_pagado', 'deliveryCambioPreparadoPor:id,name',
            ])->operativas()->deFechaOperativa($fecha)
                ->where('estado', 'entregado')
                ->latest('entregada_en')->get();

        $preordenes = Orden::with([
            'mesa:id,numero', 'cliente', 'detalles.producto:id,nombre,categoria_id', 'detalles.producto.categoria:id,nombre,parent_id', 'detalles.producto.categoria.parent:id,nombre',
            'detalles.opciones.modificadorOpcion:id,nombre,modificador_id', 'detalles.opciones.modificadorOpcion.modificador:id,color_fondo,estacion_id',
        ])->where('tipo_flujo', 'preorden')->where('estado_preorden', 'programada')
            ->where(fn ($query) => $query->whereNull('estado_solicitud')->orWhere('estado_solicitud', 'aceptada'))
            ->whereDate('fecha_programada', now()->toDateString())
            ->orderBy('fecha_programada')->get();

        $fichasEntregadas = $entregadas->map(fn ($orden) => $this->ficha($orden))->values();

        return response()->json([
            'disponibles' => $ordenes->whereNull('mesero_id')->map(fn ($orden) => $this->ficha($orden))->values(),
            'mis_fichas' => $esMesero
                ? $ordenes->where('mesero_id', $usuario->id)->map(fn ($orden) => $this->ficha($orden))->values()
                : [],
            'todas_fichas' => $ordenes->map(fn ($orden) => $this->ficha($orden))->concat($fichasEntregadas)->values(),
            'mis_entregadas' => $fichasEntregadas->filter(function ($ficha) use ($usuario) {
                $entregadores = collect($ficha['entregado_por_ids'] ?? []);
                return $entregadores->isNotEmpty()
                    ? $entregadores->contains($usuario->id)
                    : (int) ($ficha['mesero_id'] ?? 0) === (int) $usuario->id;
            })->values(),
            'preordenes_programadas' => $preordenes->map(fn ($orden) => $this->fichaPreorden($orden))->values(),
        ]);
    }

    public function tomar(Orden $orden)
    {
        $mesero = $this->meseroServicio();
        [$orden, $recomendacion] = DB::transaction(function () use ($orden, $mesero) {
            // Bloquear al usuario primero serializa dos tomas simultáneas de fichas distintas.
            DB::table('users')->where('id', $mesero->id)->lockForUpdate()->first();
            $orden = Orden::lockForUpdate()->findOrFail($orden->id);
            $this->asegurarOrdenOperativa($orden);
            abort_if(in_array($orden->estado, ['entregado', 'cancelado'], true), 422, 'La ficha ya no está disponible.');
            abort_if($orden->mesero_id && $orden->mesero_id !== $mesero->id, 409, 'Otro mesero ya tomó esta ficha.');
            if ($orden->mesero_id === $mesero->id) return [$orden, null];
            $detalleEnCamino = OrdenDetalle::with('historialCambios.user')->where('orden_id', $orden->id)->get()
                ->first(fn ($detalle) => app(ServicioColaboracionService::class)->estado($detalle)['es_apoyo']);
            if ($detalleEnCamino) {
                $estadoApoyo = app(ServicioColaboracionService::class)->estado($detalleEnCamino);
                abort(409, ($estadoApoyo['llevando_por'] ?? 'Otro mesero').' ya está llevando esta ficha como apoyo.');
            }

            $activas = Orden::where('mesero_id', $mesero->id)
                ->whereNotIn('estado', ['entregado', 'cancelado'])
                ->lockForUpdate()->get(['id'])->count();
            $orden->update(['mesero_id' => $mesero->id, 'tomada_en' => now()]);
            $recomendacion = $activas >= self::MAX_FICHAS_POR_MESERO
                ? 'Recomendación: atiende solo 2 fichas a la vez para trabajar con mayor rapidez.'
                : null;
            return [$orden, $recomendacion];
        });
        $this->notificar($orden, 'tomada');
        return response()->json(['message' => 'Ficha tomada.', 'orden_id' => $orden->id, 'recomendacion' => $recomendacion]);
    }

    public function apoyar(Request $request, Orden $orden, KdsEstacionService $kds)
    {
        $mesero = $this->meseroServicio();
        $accion = $request->validate(['accion' => ['required', 'in:llevar,cancelar,entregar']])['accion'];
        $orden = DB::transaction(function () use ($orden, $mesero, $accion, $kds) {
            $orden = Orden::lockForUpdate()->findOrFail($orden->id);
            $this->asegurarOrdenOperativa($orden);
            abort_if(in_array($orden->estado, ['entregado', 'cancelado'], true), 422, 'La ficha ya está cerrada.');
            abort_if($accion === 'llevar' && $orden->mesero_id, 409, 'La ficha ya tiene un mesero responsable.');

            $cargar = fn () => OrdenDetalle::with(['estadosEstacion', 'historialCambios.user'])
                ->where('orden_id', $orden->id)->orderBy('id')->lockForUpdate()->get();
            $detalles = $cargar();
            abort_if($detalles->isEmpty(), 422, 'La ficha no tiene productos para entregar.');
            $kds->sincronizar($detalles);
            $detalles = $cargar();
            $colaboracion = app(ServicioColaboracionService::class);
            $pendientes = $detalles->filter(fn ($detalle) => !$colaboracion->estado($detalle)['servido']);
            abort_if($pendientes->isEmpty(), 409, 'Esta ficha ya fue entregada.');

            if ($accion === 'llevar') {
                foreach ($pendientes as $detalle) {
                    $estado = $colaboracion->estado($detalle);
                    abort_if($estado['llevando_por_id'] && $estado['llevando_por_id'] !== $mesero->id, 409, ($estado['llevando_por'] ?? 'Otro mesero').' ya está llevando esta ficha.');
                    $listo = $detalle->estadosEstacion->isNotEmpty()
                        && $detalle->estadosEstacion->every(fn ($item) => in_array($item->estado, self::ESTADOS_LISTOS, true));
                    abort_unless($listo, 422, 'El pedido todavía no está completamente listo.');
                }
                foreach ($pendientes as $detalle) {
                    if (!$colaboracion->estado($detalle)['llevando_por_id']) {
                        $colaboracion->registrar($detalle, $mesero->id, 'llevar_apoyo');
                    }
                }
            } elseif ($accion === 'cancelar') {
                $propios = $pendientes->filter(fn ($detalle) => $colaboracion->estado($detalle)['llevando_por_id'] === $mesero->id);
                abort_if($propios->isEmpty(), 409, 'Esta ficha ya no está reservada por ti.');
                foreach ($propios as $detalle) $colaboracion->registrar($detalle, $mesero->id, 'cancelar');
            } else {
                foreach ($pendientes as $detalle) {
                    abort_unless($colaboracion->estado($detalle)['llevando_por_id'] === $mesero->id, 409, 'Otro mesero tomó esta ficha o la reserva venció.');
                }
                foreach ($pendientes as $detalle) {
                    $estadoAnterior = $this->estadoAnteriorEntrega($detalle);
                    $detalle->estadosEstacion()->update(['estado' => 'servido', 'fecha_servido' => now()]);
                    $detalle->update(['estado_cocina' => 'servido', 'fecha_servido' => now()]);
                    $colaboracion->registrar($detalle, $mesero->id, 'entregar', $estadoAnterior);
                }
                $orden->update(['estado' => 'entregado', 'entregada_en' => now(), 'cubiertos_entregados' => true]);
            }
            return $orden;
        });

        $this->notificarTrasRespuesta($orden, $accion === 'entregar' ? 'entregada' : 'apoyo');
        return response()->json([
            'message' => $accion === 'llevar' ? 'Ficha reservada para apoyo.' : ($accion === 'cancelar' ? 'Apoyo liberado.' : 'Pedido entregado.'),
            'orden_id' => $orden->id,
            'accion' => $accion,
        ]);
    }

    public function confirmarDetalle(OrdenDetalle $detalle, KdsEstacionService $kds)
    {
        $mesero = $this->meseroServicio();
        $orden = DB::transaction(function () use ($detalle, $mesero, $kds) {
            $ordenBloqueada = Orden::lockForUpdate()->findOrFail($detalle->orden_id);
            $detalle = OrdenDetalle::with('orden')->lockForUpdate()->findOrFail($detalle->id);
            $this->asegurarOrdenOperativa($detalle->orden);
            $colaboracion = app(ServicioColaboracionService::class);
            $estado = $colaboracion->estado($detalle);
            if ($estado['servido'] && $detalle->historialCambios->sortByDesc('id')->first(fn ($cambio) =>
                ($cambio->datos_nuevo['origen'] ?? null) === 'colaboracion_servicio'
            )?->user_id === $mesero->id) return $detalle->orden;
            abort_if($estado['servido'], 409, 'Este producto ya fue entregado.');
            abort_if($estado['llevando_por_id'] && $estado['llevando_por_id'] !== $mesero->id, 409, 'Otro mesero está llevando este producto.');
            abort_if(in_array($ordenBloqueada->estado, ['entregado', 'cancelado'], true), 422, 'La ficha ya está cerrada.');
            $kds->sincronizarDetalle($detalle);
            $estadoAnterior = $this->estadoAnteriorEntrega($detalle);
            $detalle->estadosEstacion()->update(['estado' => 'servido', 'fecha_servido' => now()]);
            $detalle->update(['estado_cocina' => 'servido', 'fecha_servido' => now()]);
            $colaboracion->registrar($detalle, $mesero->id, 'entregar', $estadoAnterior);
            return $detalle->orden;
        });
        $this->notificarTrasRespuesta($orden, 'colaboracion');
        return response()->json(['message' => 'Producto confirmado.', 'detalle_id' => $detalle->id]);
    }

    public function desconfirmarDetalle(OrdenDetalle $detalle, KdsEstacionService $kds)
    {
        $mesero = $this->meseroServicio();
        [$orden, $listo] = DB::transaction(function () use ($detalle, $mesero, $kds) {
            $orden = Orden::lockForUpdate()->findOrFail($detalle->orden_id);
            $this->asegurarOrdenOperativa($orden);
            abort_if(in_array($orden->estado, ['entregado', 'cancelado'], true), 422, 'La ficha ya está cerrada.');
            $detalle = OrdenDetalle::with(['historialCambios.user', 'estadosEstacion'])->lockForUpdate()->findOrFail($detalle->id);
            $colaboracion = app(ServicioColaboracionService::class);
            abort_unless($colaboracion->estado($detalle)['servido'], 409, 'Este producto ya está pendiente.');

            $ultimaEntrega = $detalle->historialCambios->sortByDesc('id')->first(
                fn ($cambio) => ($cambio->datos_nuevo['origen'] ?? null) === 'colaboracion_servicio'
                    && ($cambio->datos_nuevo['accion'] ?? null) === 'entregar'
            );
            $anterior = $ultimaEntrega?->datos_anterior ?? [];
            $estadosAnteriores = collect($anterior['estados_estacion'] ?? [])->keyBy('estacion_id');

            $kds->sincronizarDetalle($detalle);
            foreach ($detalle->estadosEstacion()->get() as $estadoEstacion) {
                $previo = $estadosAnteriores->get($estadoEstacion->estacion_id);
                $estadoEstacion->update([
                    'estado' => $previo['estado'] ?? 'listo_para_recoger',
                    'fecha_servido' => $previo['fecha_servido'] ?? null,
                ]);
            }
            $estadoDetalle = $anterior['estado_cocina'] ?? 'listo_para_recoger';
            $detalle->update([
                'estado_cocina' => $estadoDetalle,
                'fecha_servido' => $anterior['fecha_servido'] ?? null,
            ]);
            $colaboracion->registrar($detalle, $mesero->id, 'reabrir', [
                'estado_cocina' => 'servido',
                'restaurado_a' => $estadoDetalle,
            ]);

            $detalle->load('estadosEstacion');
            $listo = $detalle->estadosEstacion->isNotEmpty()
                && $detalle->estadosEstacion->every(fn ($estado) => in_array($estado->estado, self::ESTADOS_LISTOS, true));
            if ($orden->estado === 'listo' && !$listo) {
                $orden->update(['estado' => 'preparando']);
            }
            return [$orden, $listo];
        });

        $this->notificarTrasRespuesta($orden, 'colaboracion');
        return response()->json([
            'message' => 'Producto devuelto a su estado anterior.',
            'detalle_id' => $detalle->id,
            'listo' => $listo,
            'servido' => false,
        ]);
    }

    public function colaborar(Request $request, OrdenDetalle $detalle, KdsEstacionService $kds)
    {
        $mesero = $this->meseroServicio();
        $accion = $request->validate(['accion' => ['required', 'in:llevar,cancelar,entregar']])['accion'];
        $orden = DB::transaction(function () use ($detalle, $mesero, $accion, $kds) {
            // El mismo bloqueo de ficha serializa colaboraciones, adicionales y cierre.
            $orden = Orden::lockForUpdate()->findOrFail($detalle->orden_id);
            $this->asegurarOrdenOperativa($orden);
            abort_if(in_array($orden->estado, ['entregado', 'cancelado'], true), 422, 'La ficha ya está cerrada.');
            $detalle = OrdenDetalle::with(['producto.categoria.parent', 'historialCambios.user'])
                ->lockForUpdate()->findOrFail($detalle->id);
            $colaboracion = app(ServicioColaboracionService::class);
            $estado = $colaboracion->estado($detalle);
            $transportista = $estado['llevando_por_id'];
            abort_if($estado['servido'], 409, 'Este producto ya fue entregado.');
            if ($accion === 'llevar') {
                abort_if($transportista !== null, 409, 'Este producto ya está en camino con otro mesero.');
                $kds->sincronizarDetalle($detalle);
                $estados = $detalle->estadosEstacion()->get();
                $listo = $estados->isNotEmpty()
                    && $estados->every(fn ($item) => in_array($item->estado, self::ESTADOS_LISTOS, true));
                abort_unless($listo || $this->esSalidaInmediata($detalle), 422, 'El producto todavía no está listo para llevar.');
            } else {
                abort_unless($transportista !== null, 409, 'Primero indica que llevarás el producto.');
                abort_unless($transportista === $mesero->id || ($accion === 'cancelar' && $orden->mesero_id === $mesero->id), 403, 'Solo quien lleva el producto puede confirmar su entrega.');
            }
            if ($accion === 'entregar') {
                $estadoAnterior = $this->estadoAnteriorEntrega($detalle);
                $detalle->estadosEstacion()->update(['estado' => 'servido', 'fecha_servido' => now()]);
                $detalle->update(['estado_cocina' => 'servido', 'fecha_servido' => now()]);
            }
            $colaboracion->registrar($detalle, $mesero->id, $accion, $estadoAnterior ?? null);
            return $orden;
        });
        $this->notificar($orden, 'colaboracion');
        return response()->json(['message' => 'Colaboración registrada.', 'orden_id' => $orden->id]);
    }

    private function esSalidaInmediata(OrdenDetalle $detalle): bool
    {
        $producto = $detalle->producto;
        $texto = collect([
            $producto?->categoria?->parent?->nombre,
            $producto?->categoria?->nombre,
            $producto?->nombre,
        ])->filter()->implode(' ');
        $normalizado = mb_strtolower(Str::ascii($texto));

        return preg_match('/\b(agua|aguas|bebida|bebidas|cerveza|cervezas|coctel|cocteles|gaseosa|gaseosas|jugo|jugos|refresco|refrescos|sopa|sopas|vino|vinos)\b/u', $normalizado) === 1;
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
        $this->notificar($orden, 'liberada');
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
        foreach ($ordenes as $orden) $this->notificar($orden, 'liberada');

        return response()->json(['message' => 'Sesión cerrada y fichas liberadas.']);
    }

    public function entregar(Orden $orden)
    {
        $mesero = $this->meseroServicio();
        $orden = DB::transaction(function () use ($orden, $mesero) {
            $orden = Orden::with('detalles.estadosEstacion')->lockForUpdate()->findOrFail($orden->id);
            $this->asegurarOrdenOperativa($orden);
            abort_unless($orden->mesero_id === $mesero->id, 403, 'Esta ficha no está asignada al usuario.');
            if ($orden->estado === 'entregado') return $orden;
            abort_if(in_array($orden->estado, ['entregado', 'cancelado'], true), 422, 'La ficha ya está cerrada.');
            abort_if($orden->detalles->contains(fn ($detalle) => app(ServicioColaboracionService::class)->estado($detalle)['llevando_por_id'] !== null), 409, 'Hay productos en camino. Confirma su entrega antes de cerrar la ficha.');
            abort_unless($orden->cubiertos_entregados, 422, 'Marca los cubiertos como entregados antes de cerrar la ficha.');
            $todosListos = $orden->detalles->isNotEmpty() && $orden->detalles->every(fn ($detalle) =>
                $detalle->estadosEstacion->isNotEmpty()
                && $detalle->estadosEstacion->every(fn ($estado) => in_array($estado->estado, self::ESTADOS_LISTOS, true))
            );
            abort_unless($todosListos, 422, 'Todos los productos deben estar listos antes de entregar.');
            foreach ($orden->detalles as $detalle) {
                if (!app(ServicioColaboracionService::class)->estado($detalle)['servido']) {
                    $estadoAnterior = $this->estadoAnteriorEntrega($detalle);
                    $detalle->estadosEstacion()->update(['estado' => 'servido', 'fecha_servido' => now()]);
                    $detalle->update(['estado_cocina' => 'servido', 'fecha_servido' => now()]);
                    app(ServicioColaboracionService::class)->registrar($detalle, $mesero->id, 'entregar', $estadoAnterior);
                }
            }
            $orden->update(['estado' => 'entregado', 'entregada_en' => now()]);
            return $orden;
        });
        // La entrega ya quedó confirmada: responder antes de contactar Reverb.
        app()->terminating(fn () => $this->notificar($orden, 'entregada'));
        return response()->json(['message' => 'Pedido entregado.', 'orden_id' => $orden->id]);
    }

    public function actualizarMesa(Request $request, Orden $orden)
    {
        $this->meseroServicio();
        $data = $request->validate(['mesa_id' => ['required', 'integer', 'exists:mesas,id']]);
        $orden = DB::transaction(function () use ($orden, $data) {
            $orden = Orden::lockForUpdate()->findOrFail($orden->id);
            abort_if(in_array($orden->estado, ['entregado', 'cancelado'], true), 422, 'La ficha ya no admite cambios de mesa.');
            abort_unless($orden->tipo_orden === 'dine-in', 422, 'Solo las fichas para comer en el restaurante tienen mesa.');
            $orden->update(['mesa_id' => $data['mesa_id'], 'version' => ($orden->version ?? 0) + 1]);
            return $orden->load('mesa');
        });
        $this->notificarTrasRespuesta($orden, 'mesa');
        return response()->json(['orden_id' => $orden->id, 'mesa' => $orden->mesa->numero,
            'message' => 'Mesa actualizada.']);
    }

    public function actualizarCubiertos(Request $request, Orden $orden)
    {
        $this->meseroServicio();
        $entregados = $request->validate([
            'cubiertos_entregados' => ['required', 'boolean'],
        ])['cubiertos_entregados'];

        $orden = DB::transaction(function () use ($orden, $entregados) {
            $orden = Orden::lockForUpdate()->findOrFail($orden->id);
            $this->asegurarOrdenOperativa($orden);
            abort_if($orden->estado === 'cancelado', 422, 'No se pueden actualizar los cubiertos de una ficha cancelada.');
            $orden->update(['cubiertos_entregados' => (bool) $entregados]);
            return $orden;
        });

        $this->notificarTrasRespuesta($orden, 'cubiertos');

        return response()->json([
            'message' => $orden->cubiertos_entregados ? 'Cubiertos marcados como entregados.' : 'Cubiertos marcados como pendientes.',
            'orden_id' => $orden->id,
            'cubiertos_entregados' => $orden->cubiertos_entregados,
        ]);
    }

    public function actualizarUbicacionCliente(Request $request, Cliente $cliente)
    {
        $this->meseroServicio();
        $data = $request->validate([
            'direccion' => ['nullable', 'string', 'max:255'],
            'referencia_ubicacion' => ['nullable', 'string', 'max:255'],
            'latitud' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180'],
            'foto_local' => ['nullable', 'image', 'max:5120'],
            'eliminar_foto' => ['nullable', 'boolean'],
        ]);
        if ($request->boolean('eliminar_foto') && $cliente->foto_local) {
            Storage::disk('public')->delete($cliente->foto_local);
            $data['foto_local'] = null;
        }
        if ($request->hasFile('foto_local')) {
            if ($cliente->foto_local) Storage::disk('public')->delete($cliente->foto_local);
            $data['foto_local'] = $request->file('foto_local')->store('clientes/locales', 'public');
        }
        unset($data['eliminar_foto']);
        $cliente->update($data);
        return response()->json(['message' => 'Ubicación actualizada.', 'ubicacion_entrega' => [
            'direccion' => $cliente->direccion,
            'referencia' => $cliente->referencia_ubicacion,
            'latitud' => $cliente->latitud,
            'longitud' => $cliente->longitud,
            'foto_local_url' => $cliente->foto_local_url,
        ]]);
    }

    private function ficha(Orden $orden): array
    {
        $detalles = $orden->detalles->map(function ($detalle) {
            $listo = $detalle->estadosEstacion->isNotEmpty()
                && $detalle->estadosEstacion->every(fn ($estado) => in_array($estado->estado, self::ESTADOS_LISTOS, true));
            return [
                'id' => $detalle->id, 'cantidad' => $detalle->cantidad, 'producto' => $detalle->producto?->nombre,
                'categoria' => $detalle->producto?->categoria?->parent?->nombre ?? $detalle->producto?->categoria?->nombre ?? 'Sin categoría',
                'precio_unitario' => (float) $detalle->precio_unitario,
                'nota' => $detalle->nota, 'listo' => $listo,
                ...app(ServicioColaboracionService::class)->estado($detalle),
                'opciones' => $detalle->opciones->pluck('modificadorOpcion.nombre')->filter()->values(),
                'opciones_estilo' => $detalle->opciones->map(fn ($opcion) => [
                    'nombre' => $opcion->modificadorOpcion?->nombre,
                    'color_fondo' => $opcion->modificadorOpcion?->modificador?->color_fondo,
                ])->filter(fn ($opcion) => $opcion['nombre'])->values(),
            ];
        })->values();
        $entregadores = $detalles
            ->filter(fn ($detalle) => !empty($detalle['entregado_por_id']))
            ->map(fn ($detalle) => ['id' => (int) $detalle['entregado_por_id'], 'nombre' => $detalle['entregado_por']])
            ->unique('id')->values();
        $pendientesApoyo = $detalles->filter(fn ($detalle) => empty($detalle['servido']));
        $transportistasApoyo = $pendientesApoyo->pluck('llevando_por_id')->filter()->unique()->values();
        $esApoyoCompleto = $pendientesApoyo->isNotEmpty()
            && $transportistasApoyo->count() === 1
            && $pendientesApoyo->every(fn ($detalle) => !empty($detalle['es_apoyo']))
            && $pendientesApoyo->every(fn ($detalle) => (int) ($detalle['llevando_por_id'] ?? 0) === (int) $transportistasApoyo->first());
        $apoyoPorId = $esApoyoCompleto ? (int) $transportistasApoyo->first() : null;
        $apoyoPor = $esApoyoCompleto ? $pendientesApoyo->first()['llevando_por'] : null;
        $apoyoHasta = $esApoyoCompleto ? $pendientesApoyo->first()['llevando_hasta'] : null;
        $esDelivery = $orden->tipo_orden === 'delivery';
        $saldo = $esDelivery ? round((float) $orden->saldo_pendiente, 2) : null;
        $montoEsperado = $esDelivery && $orden->delivery_monto_esperado !== null
            ? round((float) $orden->delivery_monto_esperado, 2)
            : null;
        return [
            'id' => $orden->id, 'numero_orden' => $orden->numero_orden, 'created_at' => $orden->created_at,
            'entregada_en' => $orden->entregada_en?->toIso8601String(),
            'cubiertos_entregados' => (bool) $orden->cubiertos_entregados,
            'mesa' => $orden->mesa?->numero, 'cliente' => $orden->cliente?->nombre, 'cliente_id' => $orden->cliente_id,
            'observaciones' => $orden->comentarioGeneral(),
            'ubicacion_entrega' => $this->ubicacionEntrega($orden),
            'tipo_orden' => $orden->tipo_orden,
            'tipo_flujo' => $orden->tipo_flujo,
            'estado_preorden' => $orden->estado_preorden,
            'preorden_activada_en' => $orden->preorden_activada_en?->toIso8601String(),
            'hora' => ($orden->fecha_orden ?? $orden->created_at)?->format('H:i'),
            'tiempo_espera_minutos' => (int) ($orden->fecha_orden ?? $orden->created_at)?->diffInMinutes(now()),
            'mesero' => $orden->mesero?->name, 'mesero_id' => $orden->mesero_id, 'estado' => $orden->estado, 'detalles' => $detalles,
            'apoyo_por_id' => $apoyoPorId,
            'apoyo_por' => $apoyoPor,
            'apoyo_hasta' => $apoyoHasta,
            'entregado_por_ids' => $entregadores->pluck('id'),
            'entregado_por_nombres' => $entregadores->pluck('nombre'),
            'total' => $esDelivery ? round((float) $orden->total, 2) : null,
            'saldo_pendiente' => $saldo,
            'delivery_monto_esperado' => $montoEsperado,
            'delivery_cambio_preparado' => $esDelivery && (bool) $orden->delivery_cambio_preparado,
            'delivery_cambio' => $montoEsperado !== null && $saldo !== null ? round(max(0, $montoEsperado - $saldo), 2) : null,
            'delivery_cambio_preparado_por' => $orden->deliveryCambioPreparadoPor?->name,
            'delivery_cambio_preparado_en' => $orden->delivery_cambio_preparado_en?->toIso8601String(),
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
            'cliente_id' => $orden->cliente_id,
            'observaciones' => $orden->comentarioGeneral(),
            'ubicacion_entrega' => $this->ubicacionEntrega($orden),
            'tipo_orden' => $orden->tipo_orden,
            'fecha_programada' => $orden->fecha_programada,
            'estado_preorden' => $orden->estado_preorden,
            'detalles' => $orden->detalles->map(fn ($detalle) => [
                'id' => $detalle->id,
                'cantidad' => $detalle->cantidad,
                'producto' => $detalle->producto?->nombre,
                'categoria' => $detalle->producto?->categoria?->parent?->nombre ?? $detalle->producto?->categoria?->nombre ?? 'Sin categoría',
                'precio_unitario' => (float) $detalle->precio_unitario,
                'nota' => $detalle->nota,
                'opciones' => $detalle->opciones->pluck('modificadorOpcion.nombre')->filter()->values(),
                'opciones_estilo' => $detalle->opciones->map(fn ($opcion) => [
                    'nombre' => $opcion->modificadorOpcion?->nombre,
                    'color_fondo' => $opcion->modificadorOpcion?->modificador?->color_fondo,
                ])->filter(fn ($opcion) => $opcion['nombre'])->values(),
                'listo' => false,
            ])->values(),
            'total_items' => $orden->detalles->count(),
            'bloqueada' => true,
        ];
    }

    private function ubicacionEntrega(Orden $orden): ?array
    {
        if ($orden->tipo_orden !== 'delivery' || !$orden->cliente) return null;
        return [
            'direccion' => $orden->cliente->direccion,
            'referencia' => $orden->cliente->referencia_ubicacion,
            'latitud' => $orden->cliente->latitud,
            'longitud' => $orden->cliente->longitud,
            'foto_local_url' => $orden->cliente->foto_local_url,
        ];
    }

    private function asegurarOrdenOperativa(Orden $orden): void
    {
        abort_if($orden->esPreordenProgramada(), 422, 'La preorden está pendiente de activación y no puede procesarse.');
    }

    private function estadoAnteriorEntrega(OrdenDetalle $detalle): array
    {
        return [
            'estado_cocina' => $detalle->estado_cocina,
            'fecha_servido' => $detalle->fecha_servido?->toIso8601String(),
            'estados_estacion' => $detalle->estadosEstacion()->get()->map(fn ($estado) => [
                'estacion_id' => $estado->estacion_id,
                'estado' => $estado->estado,
                'fecha_servido' => $estado->fecha_servido?->toIso8601String(),
            ])->values()->all(),
        ];
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

    private function notificarTrasRespuesta(Orden $orden, string $accion): void
    {
        if (app()->runningUnitTests()) {
            $this->notificar($orden, $accion);
            return;
        }

        app()->terminating(fn () => $this->notificar($orden, $accion));
    }

    private function notificar(Orden $orden, string $accion = 'actualizada'): void
    {
        try {
            $ficha = null;
            if ($accion === 'liberada') {
                $orden->load([
                    'mesa:id,numero', 'cliente', 'detalles.producto:id,nombre,categoria_id', 'detalles.producto.categoria:id,nombre,parent_id', 'detalles.producto.categoria.parent:id,nombre',
                    'detalles.opciones.modificadorOpcion:id,nombre', 'detalles.estadosEstacion', 'detalles.historialCambios.user:id,name',
                    'mesero:id,name',
                ]);
                $ficha = $this->ficha($orden);
            }
            $evento = ServicioFichaActualizadaEvent::desdeOrden($orden, $accion, $ficha);
            if ($accion === 'colaboracion') {
                $evento->actividad = ['user_id' => auth('api')->id(), 'mensaje' => auth('api')->user()?->name.' actualizó la entrega de productos de tu ficha #'.$orden->numero_orden.'.'];
            }
            event($evento);
            event(new OrdenCocinaActualizadaEvent($orden, [], 'servicio'));
        }
        catch (\Throwable $e) { Log::warning('No se pudo notificar el cambio de servicio.', ['orden_id' => $orden->id, 'error' => $e->getMessage()]); }
    }
}
