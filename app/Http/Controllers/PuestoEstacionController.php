<?php

namespace App\Http\Controllers;

use App\Events\OrdenCocinaActualizadaEvent;
use App\Events\PuestoCocinaActualizadoEvent;
use App\Events\PuestoCocinaOrdenAsignadaEvent;
use App\Events\PuestoCocinaOrdenListaEvent;
use App\Services\AsignadorOrdenCocinaService;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\PuestoEstacion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PuestoEstacionController extends Controller
{
    public function index()
    {
        $cocina = $this->getCocinaEstacion();

        $puestos = PuestoEstacion::with(['usuario', 'orden'])
            ->where('estacion_id', $cocina->id)
            ->orderBy('nombre')
            ->get();

        return response()->json(['puestos' => $puestos]);
    }

    public function control()
    {
        $user = $this->getAuthenticatedCocinero();
        $cocina = $this->getCocinaEstacion();

        $puestos = PuestoEstacion::with(['usuario', 'orden.cliente', 'orden.mesa', 'orden.detalles.producto.categoria', 'orden.detalles.estacion'])
            ->where('estacion_id', $cocina->id)
            ->orderBy('nombre')
            ->get();

        $puestoActual = $puestos->firstWhere('user_id', $user->id);

        $ordenesAsignadas = $puestos->pluck('orden_id')->filter()->values();

        $ordenesDisponibles = Orden::with(['cliente:id,nombre', 'mesa:id,numero', 'detalles.producto.categoria', 'detalles.estacion'])
            ->whereDate('created_at', now()->toDateString())
            ->whereHas('detalles', function ($q) use ($user) {
                $q->where('estacion_id', $user->estacion_id)
                    ->where('estado_cocina', 'pendiente');
            })
            ->when($ordenesAsignadas->isNotEmpty(), function ($query) use ($ordenesAsignadas) {
                return $query->whereNotIn('id', $ordenesAsignadas);
            })
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'usuario' => $user,
            'puesto' => $puestoActual,
            'estado' => $puestoActual?->orden_estado_cocina ?? 'sin_orden',
            'puestos' => $puestos,
            'ordenes_disponibles' => $ordenesDisponibles,
        ]);
    }

    public function ocupar(PuestoEstacion $puesto)
    {
        $user = $this->getAuthenticatedCocinero();
        $this->ensurePuestoBelongsToCocina($puesto);

        $puestoActualizado = DB::transaction(function () use ($puesto, $user) {
            $puesto = PuestoEstacion::where('id', $puesto->id)
                ->lockForUpdate()
                ->with('usuario', 'estacion')
                ->firstOrFail();

            if ($puesto->user_id === $user->id) {
                return $puesto;
            }

            if ($puesto->user_id !== null) {
                abort(Response::HTTP_CONFLICT, 'El puesto ya está ocupado.');
            }

            $ocupacionActual = PuestoEstacion::where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($ocupacionActual !== null) {
                abort(Response::HTTP_CONFLICT, 'El usuario ya ocupa otro puesto.');
            }

            $puesto->user_id = $user->id;
            $puesto->save();
            $puesto->load('usuario');

            event(new PuestoCocinaActualizadoEvent($puesto));

            return $puesto;
        });

        // Intentar asignar automáticamente una orden a este puesto recién ocupado
        try {
            app(AsignadorOrdenCocinaService::class)->asignarParaPuesto($puestoActualizado);
        } catch (\Throwable $e) {
            // No interrumpir la respuesta si el asignador falla
        }

        return response()->json(['puesto' => $puestoActualizado]);
    }

    public function asignarOrden(PuestoEstacion $puesto, Request $request)
    {
        $user = $this->getAuthenticatedCocinero();
        $this->ensurePuestoBelongsToCocina($puesto);

        $data = $request->validate([
            'orden_id' => 'required|integer|exists:ordenes,id',
        ]);

        $orden = Orden::where('id', $data['orden_id'])
            ->with('detalles')
            ->firstOrFail();

        if (!$orden->detalles->contains(fn ($detalle) => $detalle->estacion_id === $user->estacion_id)) {
            abort(Response::HTTP_FORBIDDEN, 'La orden no pertenece a la estación Cocina.');
        }

        $puestoActualizado = DB::transaction(function () use ($puesto, $user, $orden) {
            $puesto = PuestoEstacion::where('id', $puesto->id)
                ->lockForUpdate()
                ->with('usuario', 'estacion', 'orden')
                ->firstOrFail();

            if ($puesto->user_id !== $user->id) {
                abort(Response::HTTP_FORBIDDEN, 'Solo el ocupante actual puede asignar una orden al puesto.');
            }

            if ($puesto->orden_id === $orden->id) {
                return $puesto;
            }

            if ($puesto->orden_id !== null) {
                abort(Response::HTTP_CONFLICT, 'El puesto ya tiene una orden activa. Libera la orden actual antes de asignar otra.');
            }

            $otroPuestoAsignado = PuestoEstacion::where('orden_id', $orden->id)
                ->lockForUpdate()
                ->first();

            if ($otroPuestoAsignado !== null) {
                abort(Response::HTTP_CONFLICT, 'La orden ya está asignada a otro puesto.');
            }

            $puesto->orden_id = $orden->id;
            $puesto->save();

            OrdenDetalle::where('orden_id', $orden->id)
                ->where('estacion_id', $puesto->estacion_id)
                ->where('estado_cocina', 'pendiente')
                ->update(['estado_cocina' => 'en_preparacion']);

            $orden->refresh();
            $orden->estado = $orden->detalles()->where('estado_cocina', 'pendiente')->exists() ? 'preparando' : 'listo';
            $orden->save();

            $ordenActualizada = $orden->fresh(['cliente:id,nombre', 'mesa:id,numero', 'detalles.producto.categoria', 'detalles.estacion']);
            $puesto->load('usuario', 'orden');

            event(new PuestoCocinaOrdenAsignadaEvent($puesto));
            event(new OrdenCocinaActualizadaEvent($ordenActualizada));

            return $puesto;
        });

        return response()->json(['puesto' => $puestoActualizado]);
    }

    public function liberarOrden(PuestoEstacion $puesto)
    {
        $user = $this->getAuthenticatedCocinero();
        $this->ensurePuestoBelongsToCocina($puesto);

        $puestoActualizado = DB::transaction(function () use ($puesto, $user) {
            $puesto = PuestoEstacion::where('id', $puesto->id)
                ->lockForUpdate()
                ->with('usuario', 'estacion', 'orden')
                ->firstOrFail();

            if ($puesto->user_id !== $user->id) {
                abort(Response::HTTP_FORBIDDEN, 'Solo el ocupante actual puede liberar la orden del puesto.');
            }

            if ($puesto->orden_id !== null) {
                $orden = Orden::with('detalles')->find($puesto->orden_id);

                OrdenDetalle::where('orden_id', $puesto->orden_id)
                    ->where('estacion_id', $puesto->estacion_id)
                    ->where('estado_cocina', 'en_preparacion')
                    ->update(['estado_cocina' => 'pendiente']);

                if ($orden) {
                    $orden->refresh();
                    $orden->estado = $orden->detalles()->where('estado_cocina', 'pendiente')->exists() ? 'preparando' : ($orden->detalles()->where('estado_cocina', 'listo_para_recoger')->exists() ? 'listo' : $orden->estado);
                    $orden->save();
                    event(new OrdenCocinaActualizadaEvent($orden));
                }
            }

            $puesto->orden_id = null;
            $puesto->save();
            $puesto->load('usuario', 'orden');

            event(new PuestoCocinaActualizadoEvent($puesto));

            return $puesto;
        });

        // Después de liberar la orden, ejecutar asignador automático para cubrir puestos libres
        try {
            app(AsignadorOrdenCocinaService::class)->asignarSiguienteOrdenDisponible();
        } catch (\Throwable $e) {
            // ignorar errores del asignador
        }

        return response()->json(['puesto' => $puestoActualizado]);
    }

    public function ordenarLista(PuestoEstacion $puesto)
    {
        $user = $this->getAuthenticatedCocinero();
        $this->ensurePuestoBelongsToCocina($puesto);

        $resultado = DB::transaction(function () use ($puesto, $user) {
            $puesto = PuestoEstacion::where('id', $puesto->id)
                ->lockForUpdate()
                ->with(['usuario', 'estacion', 'orden'])
                ->firstOrFail();

            if ($puesto->user_id !== $user->id) {
                abort(Response::HTTP_FORBIDDEN, 'Solo el ocupante actual puede marcar la orden como lista.');
            }

            if ($puesto->orden_id === null) {
                abort(Response::HTTP_CONFLICT, 'No hay una orden asignada a este puesto.');
            }

            $detalleCount = OrdenDetalle::where('orden_id', $puesto->orden_id)
                ->where('estacion_id', $puesto->estacion_id)
                ->whereIn('estado_cocina', ['pendiente', 'en_preparacion'])
                ->lockForUpdate()
                ->count();

            if ($detalleCount === 0) {
                abort(Response::HTTP_CONFLICT, 'No hay detalles pendientes de preparación para esta orden en este puesto.');
            }

            OrdenDetalle::where('orden_id', $puesto->orden_id)
                ->where('estacion_id', $puesto->estacion_id)
                ->whereIn('estado_cocina', ['pendiente', 'en_preparacion'])
                ->update(['estado_cocina' => 'listo_para_recoger']);

            $orden = Orden::lockForUpdate()->findOrFail($puesto->orden_id);
            $orden->refresh();
            $orden->estado = $orden->detalles()->where('estado_cocina', 'pendiente')->exists() ? 'preparando' : 'listo';
            $orden->save();

            $puesto->orden_id = null;
            $puesto->save();
            $puesto->setRelation('orden', null);

            return compact('puesto', 'orden');
        });

        event(new PuestoCocinaOrdenListaEvent($resultado['puesto'], $resultado['orden'], 'listo_para_recoger', $user));

        // Ejecutar asignador automático tras marcar lista
        try {
            app(AsignadorOrdenCocinaService::class)->asignarSiguienteOrdenDisponible();
        } catch (\Throwable $e) {
            // ignorar errores del asignador
        }

        return response()->json([
            'puesto' => $resultado['puesto'],
            'orden' => $resultado['orden'],
        ]);
    }

    public function liberar(PuestoEstacion $puesto)
    {
        $user = $this->getAuthenticatedCocinero();
        $this->ensurePuestoBelongsToCocina($puesto);

        $puestoActualizado = DB::transaction(function () use ($puesto, $user) {
            $puesto = PuestoEstacion::where('id', $puesto->id)
                ->lockForUpdate()
                ->with('usuario', 'estacion')
                ->firstOrFail();

            if ($puesto->user_id !== $user->id) {
                abort(Response::HTTP_FORBIDDEN, 'Solo el ocupante actual puede liberar este puesto.');
            }

            $puesto->user_id = null;
            $puesto->orden_id = null;
            $puesto->save();
            $puesto->load('usuario');

            event(new PuestoCocinaActualizadoEvent($puesto));

            return $puesto;
        });

        // Ejecutar asignador automático cuando un puesto queda libre
        try {
            app(AsignadorOrdenCocinaService::class)->asignarSiguienteOrdenDisponible();
        } catch (\Throwable $e) {
        }

        return response()->json(['puesto' => $puestoActualizado]);
    }

    private function getAuthenticatedCocinero()
    {
        /** @var User $user */
        $user = Auth::user();

        if (!$user) {
            abort(401, 'Usuario no autenticado.');
        }

        $user->loadMissing(['role', 'estacion']);

        if ($user->estacion?->codigo !== 'COCINA') {
            abort(403, 'El usuario no pertenece a la estación Cocina.');
        }

        if ($user->role?->nombre !== 'Cocinero') {
            abort(403, 'El usuario no está autorizado.');
        }

        return $user;
    }

    private function getCocinaEstacion()
    {
        return \App\Models\EstacionTrabajo::where('codigo', 'COCINA')->firstOrFail();
    }

    private function ensurePuestoBelongsToCocina(PuestoEstacion $puesto): void
    {
        $puesto->loadMissing('estacion');

        if ($puesto->estacion?->codigo !== 'COCINA') {
            abort(Response::HTTP_FORBIDDEN, 'El puesto no pertenece a la estación Cocina.');
        }
    }
}
