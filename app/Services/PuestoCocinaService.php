<?php

namespace App\Services;

use App\Events\OrdenCocinaActualizadaEvent;
use App\Events\PuestoCocinaActualizadoEvent;
use App\Events\PuestoCocinaOrdenAsignadaEvent;
use App\Events\PuestoCocinaOrdenListaEvent;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\PuestoEstacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PuestoCocinaService
{
    public function __construct(
        private PrioridadOrdenCocinaService $prioridadService
    ) {}

    public function ocuparPuesto(PuestoEstacion $puesto, int $userId): PuestoEstacion
    {
        $puesto = DB::transaction(function () use ($puesto, $userId) {
            $puesto = PuestoEstacion::where('id', $puesto->id)
                ->lockForUpdate()
                ->with(['usuario', 'estacion', 'orden'])
                ->firstOrFail();

            if ($puesto->user_id !== null && $puesto->user_id !== $userId) {
                throw new \RuntimeException('El puesto ya está ocupado por otro usuario.');
            }

            $ocupacionActual = PuestoEstacion::where('user_id', $userId)
                ->where('id', '!=', $puesto->id)
                ->lockForUpdate()
                ->first();

            if ($ocupacionActual !== null) {
                throw new \RuntimeException('El usuario ya ocupa otro puesto.');
            }

            if ($puesto->user_id === null) {
                $puesto->user_id = $userId;
                $puesto->save();
            }

            return $puesto;
        });

        event(new PuestoCocinaActualizadoEvent($puesto));

        if ($puesto->orden_id === null) {
            $asignado = $this->asignarParaPuesto($puesto);
            if ($asignado) {
                return $asignado;
            }
        }

        return $puesto->load(['usuario', 'orden.cliente', 'orden.mesa', 'orden.detalles.producto.categoria', 'orden.detalles.estacion']);
    }

    // public function asignarParaPuesto(PuestoEstacion $puesto): ?PuestoEstacion
    // {
    //     $resultado = DB::transaction(function () use ($puesto) {
    //         $puesto = PuestoEstacion::where('id', $puesto->id)
    //             ->lockForUpdate()
    //             ->with(['usuario', 'estacion', 'orden'])
    //             ->first();

    //         if (!$puesto || $puesto->user_id === null || $puesto->orden_id !== null) {
    //             return null;
    //         }

    //         $ordenes = $this->prioridadService->obtenerColaPriorizada($puesto->estacion_id);
    //         foreach ($ordenes as $ordenPrioritaria) {
    //             $orden = Orden::where('id', $ordenPrioritaria->id)
    //                 ->lockForUpdate()
    //                 ->with(['detalles.producto.categoria', 'detalles.estacion'])
    //                 ->first();

    //             if (!$orden) {
    //                 Log::warning('PuestoCocinaService: orden prioritaria no encontrada', [
    //                     'orden_id' => $ordenPrioritaria->id,
    //                     'puesto_id' => $puesto->id,
    //                 ]);
    //                 continue;
    //             }

    //             $otroPuesto = PuestoEstacion::where('orden_id', $orden->id)->lockForUpdate()->first();
    //             if ($otroPuesto) {
    //                 continue;
    //             }

    //             return $this->asignarOrdenAlPuesto($puesto, $orden);
    //         }

    //         $ordenesDisponibles = $this->obtenerOrdenesDisponibles($puesto->estacion_id);
    //         if ($ordenesDisponibles->isNotEmpty()) {
    //             Log::info('PuestoCocinaService: prioridad no devolvió ordenes, intentando asignar desde ordenes disponibles', [
    //                 'puesto_id' => $puesto->id,
    //                 'estacion_id' => $puesto->estacion_id,
    //                 'ordenes_disponibles' => $ordenesDisponibles->pluck('id')->all(),
    //             ]);

    //             foreach ($ordenesDisponibles as $ordenDisponible) {
    //                 $orden = Orden::where('id', $ordenDisponible->id)
    //                     ->lockForUpdate()
    //                     ->with(['detalles.producto.categoria', 'detalles.estacion'])
    //                     ->first();

    //                 if (!$orden) {
    //                     continue;
    //                 }

    //                 $otroPuesto = PuestoEstacion::where('orden_id', $orden->id)->lockForUpdate()->first();
    //                 if ($otroPuesto) {
    //                     continue;
    //                 }

    //                 return $this->asignarOrdenAlPuesto($puesto, $orden);
    //             }
    //         }

    //         return null;
    //     });

    //     if (!$resultado) {
    //         return null;
    //     }

    //     event(new PuestoCocinaOrdenAsignadaEvent($resultado));
    //     event(new OrdenCocinaActualizadaEvent($resultado->orden));

    //     return $resultado;
    // }

    public function asignarParaPuesto(PuestoEstacion $puesto): ?PuestoEstacion
{
    Log::info('================ INICIO asignarParaPuesto ================', [
        'puesto_id' => $puesto->id,
    ]);

    $resultado = DB::transaction(function () use ($puesto) {

        Log::info('1. Buscando puesto...', [
            'puesto_id' => $puesto->id,
        ]);

        $puesto = PuestoEstacion::where('id', $puesto->id)
            ->lockForUpdate()
            ->with(['usuario', 'estacion', 'orden'])
            ->first();

        Log::info('2. Puesto encontrado', [
            'existe' => $puesto !== null,
            'user_id' => $puesto?->user_id,
            'orden_id' => $puesto?->orden_id,
            'estacion_id' => $puesto?->estacion_id,
        ]);

        if (!$puesto) {
            Log::warning('El puesto no existe.');
            return null;
        }

        if ($puesto->user_id === null) {
            Log::warning('El puesto no tiene usuario.');
            return null;
        }

        if ($puesto->orden_id !== null) {
            Log::warning('El puesto ya tiene una orden.', [
                'orden_id' => $puesto->orden_id,
            ]);
            return null;
        }

        Log::info('3. Consultando cola priorizada...');

        $ordenes = $this->prioridadService->obtenerColaPriorizada($puesto->estacion_id);

        Log::info('4. Cola obtenida', [
            'cantidad' => $ordenes->count(),
            'ids' => $ordenes->pluck('id')->all(),
        ]);

        foreach ($ordenes as $ordenPrioritaria) {

            Log::info('5. Evaluando orden prioritaria', [
                'orden_id' => $ordenPrioritaria->id,
            ]);

            $orden = Orden::where('id', $ordenPrioritaria->id)
                ->lockForUpdate()
                ->with([
                    'cliente',
                    'mesa',
                    'detalles.producto.categoria',
                    'detalles.estacion'
                ])
                ->first();

            if (!$orden) {
                Log::warning('La orden ya no existe.', [
                    'orden_id' => $ordenPrioritaria->id,
                ]);
                continue;
            }

            Log::info('6. Orden encontrada', [
                'orden_id' => $orden->id,
                'estado' => $orden->estado,
            ]);

            $otroPuesto = PuestoEstacion::where('orden_id', $orden->id)
                ->lockForUpdate()
                ->first();

            if ($otroPuesto) {
                Log::warning('La orden ya está asignada.', [
                    'orden_id' => $orden->id,
                    'puesto_id' => $otroPuesto->id,
                ]);
                continue;
            }

            Log::info('7. Llamando asignarOrdenAlPuesto()', [
                'puesto_id' => $puesto->id,
                'orden_id' => $orden->id,
            ]);

            $resultado = $this->asignarOrdenAlPuesto($puesto, $orden);

            Log::info('8. Resultado asignarOrdenAlPuesto()', [
                'resultado' => $resultado !== null,
                'puesto_orden_id' => $resultado?->orden_id,
            ]);

            return $resultado;
        }

        Log::warning('9. No hubo ninguna orden priorizada asignable.');

        $ordenesDisponibles = $this->obtenerOrdenesDisponibles($puesto->estacion_id);

        Log::info('10. Órdenes disponibles', [
            'cantidad' => $ordenesDisponibles->count(),
            'ids' => $ordenesDisponibles->pluck('id')->all(),
        ]);

        foreach ($ordenesDisponibles as $ordenDisponible) {

            Log::info('11. Evaluando orden disponible', [
                'orden_id' => $ordenDisponible->id,
            ]);

            $orden = Orden::where('id', $ordenDisponible->id)
                ->lockForUpdate()
                ->with([
                    'cliente',
                    'mesa',
                    'detalles.producto.categoria',
                    'detalles.estacion'
                ])
                ->first();

            if (!$orden) {
                continue;
            }

            $otroPuesto = PuestoEstacion::where('orden_id', $orden->id)
                ->lockForUpdate()
                ->first();

            if ($otroPuesto) {
                Log::warning('La orden disponible ya fue asignada.', [
                    'orden_id' => $orden->id,
                    'puesto_id' => $otroPuesto->id,
                ]);
                continue;
            }

            Log::info('12. Asignando orden disponible', [
                'orden_id' => $orden->id,
            ]);

            $resultado = $this->asignarOrdenAlPuesto($puesto, $orden);

            Log::info('13. Resultado asignación disponible', [
                'resultado' => $resultado !== null,
                'puesto_orden_id' => $resultado?->orden_id,
            ]);

            return $resultado;
        }

        Log::warning('14. No se encontró ninguna orden para asignar.');

        return null;
    });

    Log::info('15. Fin de la transacción', [
        'resultado' => $resultado !== null,
        'orden_id' => $resultado?->orden_id,
    ]);

    if (!$resultado) {
        Log::warning('No hubo asignación.');
        return null;
    }

    Log::info('16. Emitiendo eventos', [
        'orden_id' => $resultado->orden_id,
    ]);

    event(new PuestoCocinaOrdenAsignadaEvent($resultado));
    event(new OrdenCocinaActualizadaEvent($resultado->orden));

    Log::info('================ FIN asignarParaPuesto ================');

    return $resultado;
}

    public function marcarLista(PuestoEstacion $puesto): array
    {
        $orden = null;

        $puesto = DB::transaction(function () use ($puesto, &$orden) {
            $puesto = PuestoEstacion::where('id', $puesto->id)
                ->lockForUpdate()
                ->with(['usuario', 'estacion', 'orden'])
                ->firstOrFail();

            if ($puesto->user_id === null) {
                throw new \RuntimeException('El puesto no está ocupado.');
            }

            if ($puesto->orden_id === null) {
                throw new \RuntimeException('No hay una orden asignada a este puesto.');
            }

            $orden = Orden::lockForUpdate()->findOrFail($puesto->orden_id);

            OrdenDetalle::where('orden_id', $orden->id)
                ->where('estacion_id', $puesto->estacion_id)
                ->whereIn('estado_cocina', ['pendiente', 'en_preparacion'])
                ->update(['estado_cocina' => 'listo_para_recoger']);

            $orden->refresh();
            $orden->estado = $orden->detalles()->where('estado_cocina', 'pendiente')->exists() ? 'preparando' : 'listo';
            $orden->save();

            $puesto->orden_id = null;
            $puesto->save();

            return $puesto;
        });

        /** @var Orden $orden */
        event(new PuestoCocinaOrdenListaEvent($puesto, $orden, 'listo_para_recoger', $puesto->usuario));
        event(new OrdenCocinaActualizadaEvent($orden));

        if ($puesto->user_id !== null) {
            $siguiente = $this->asignarParaPuesto($puesto);
            return [
                'puesto' => $siguiente ?? $puesto->load(['usuario', 'orden.cliente', 'orden.mesa', 'orden.detalles.producto.categoria', 'orden.detalles.estacion']),
                'orden' => $orden,
            ];
        }

        return [
            'puesto' => $puesto->load(['usuario', 'orden.cliente', 'orden.mesa', 'orden.detalles.producto.categoria', 'orden.detalles.estacion']),
            'orden' => $orden,
        ];
    }

    public function liberarOrden(PuestoEstacion $puesto): PuestoEstacion
    {
        $orden = null;

        $puesto = DB::transaction(function () use ($puesto, &$orden) {
            $puesto = PuestoEstacion::where('id', $puesto->id)
                ->lockForUpdate()
                ->with(['usuario', 'estacion', 'orden'])
                ->firstOrFail();

            if ($puesto->user_id === null) {
                throw new \RuntimeException('El puesto no está ocupado.');
            }

            if ($puesto->orden_id === null) {
                return $puesto;
            }

            $orden = Orden::lockForUpdate()->findOrFail($puesto->orden_id);

            OrdenDetalle::where('orden_id', $orden->id)
                ->where('estacion_id', $puesto->estacion_id)
                ->where('estado_cocina', 'en_preparacion')
                ->update(['estado_cocina' => 'pendiente']);

            $orden->refresh();
            $orden->estado = $orden->detalles()->where('estado_cocina', 'pendiente')->exists()
                ? 'preparando'
                : ($orden->detalles()->where('estado_cocina', 'listo_para_recoger')->exists() ? 'listo' : $orden->estado);
            $orden->save();

            $puesto->orden_id = null;
            $puesto->save();

            return $puesto;
        });

        if ($orden) {
            event(new OrdenCocinaActualizadaEvent($orden));
        }

        event(new PuestoCocinaActualizadoEvent($puesto));

        if ($puesto->user_id !== null) {
            $siguiente = $this->asignarParaPuesto($puesto);
            return $siguiente ?? $puesto->load(['usuario', 'orden.cliente', 'orden.mesa', 'orden.detalles.producto.categoria', 'orden.detalles.estacion']);
        }

        return $puesto->load(['usuario', 'orden.cliente', 'orden.mesa', 'orden.detalles.producto.categoria', 'orden.detalles.estacion']);
    }

    // public function liberarPuesto(PuestoEstacion $puesto): PuestoEstacion
    // {
    //     $puesto = DB::transaction(function () use ($puesto) {
    //         $puesto = PuestoEstacion::where('id', $puesto->id)
    //             ->lockForUpdate()
    //             ->with(['usuario', 'estacion', 'orden'])
    //             ->firstOrFail();

    //         $puesto->user_id = null;
    //         $puesto->orden_id = null;
    //         $puesto->save();

    //         return $puesto;
    //     });

    //     event(new PuestoCocinaActualizadoEvent($puesto));

    //     return $puesto->load(['usuario', 'orden.cliente', 'orden.mesa', 'orden.detalles.producto.categoria', 'orden.detalles.estacion']);
    // }
    public function liberarPuesto(PuestoEstacion $puesto): PuestoEstacion
{
    $orden = null;

    $puesto = DB::transaction(function () use ($puesto, &$orden) {

        $puesto = PuestoEstacion::where('id', $puesto->id)
            ->lockForUpdate()
            ->with(['orden'])
            ->firstOrFail();

        if ($puesto->user_id === null) {
            throw new \RuntimeException('El puesto no está ocupado.');
        }

        // Si tenía una orden asignada
        if ($puesto->orden_id !== null) {

            $orden = Orden::lockForUpdate()
                ->findOrFail($puesto->orden_id);


            // Devolver trabajo pendiente de esta estación
            OrdenDetalle::where('orden_id', $orden->id)
                ->where('estacion_id', $puesto->estacion_id)
                ->where('estado_cocina', 'en_preparacion')
                ->update([
                    'estado_cocina' => 'pendiente'
                ]);


            event(new OrdenCocinaActualizadaEvent($orden));
        }


        // Liberar completamente el puesto
        $puesto->update([
            'user_id' => null,
            'orden_id' => null
        ]);


        return $puesto;
    });


    event(new PuestoCocinaActualizadoEvent($puesto));


    return $puesto->load([
        'usuario',
        'orden.cliente',
        'orden.mesa',
        'orden.detalles.producto.categoria',
        'orden.detalles.estacion'
    ]);
}

    public function cambiarPedido(PuestoEstacion $puesto, Orden $orden): PuestoEstacion
    {
        $resultado = DB::transaction(function () use ($puesto, $orden) {
            $puesto = PuestoEstacion::where('id', $puesto->id)
                ->lockForUpdate()
                ->with(['usuario', 'estacion', 'orden'])
                ->firstOrFail();

            if ($puesto->user_id === null) {
                throw new \RuntimeException('El puesto no está ocupado.');
            }

            if ($orden->estacion_id !== $puesto->estacion_id) {
                throw new \RuntimeException('La orden no pertenece a esta estación.');
            }

            if ($puesto->orden_id === $orden->id) {
                return $puesto;
            }

            if ($puesto->orden_id !== null && $puesto->orden_id !== $orden->id) {
                $detallesActivos = OrdenDetalle::where('orden_id', $puesto->orden_id)
                    ->where('estacion_id', $puesto->estacion_id)
                    ->whereNotIn('estado_cocina', ['pendiente'])
                    ->exists();

                if ($detallesActivos) {
                    throw new \RuntimeException('No se puede cambiar el pedido porque ya comenzó su preparación.');
                }

                $puesto->orden_id = null;
                $puesto->save();
            }

            return $this->asignarOrdenAlPuesto($puesto, $orden);
        });

        if ($resultado && $resultado->orden_id !== null) {
            event(new PuestoCocinaOrdenAsignadaEvent($resultado));
            event(new OrdenCocinaActualizadaEvent($resultado->orden));
        }

        return $resultado;
    }

    public function obtenerOrdenesDisponibles(int $estacionId)
    {
        $ordenesAsignadas = PuestoEstacion::whereNotNull('orden_id')->pluck('orden_id')->filter()->values();

        return Orden::with(['cliente:id,nombre', 'mesa:id,numero', 'detalles.producto.categoria', 'detalles.estacion'])
            ->operativas()->deFechaOperativa(now()->toDateString())
            ->whereHas('detalles', function ($query) use ($estacionId) {
                $query->where('estacion_id', $estacionId)
                    ->where(function ($query) {
                        $query->where('estado_cocina', 'pendiente')
                              ->orWhereNull('estado_cocina');
                    });
            })
            ->when($ordenesAsignadas->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $ordenesAsignadas))
            ->orderBy('created_at')
            ->get();
    }

    public function obtenerControlPuesto(int $userId)
    {
        return PuestoEstacion::with(['usuario', 'orden.cliente', 'orden.mesa', 'orden.detalles.producto.categoria', 'orden.detalles.estacion'])
            ->where('user_id', $userId)
            ->first();
    }

    // private function asignarOrdenAlPuesto(PuestoEstacion $puesto, Orden $orden): PuestoEstacion
    // {
    //     if ($puesto->orden_id !== null) {
    //         throw new \RuntimeException('El puesto ya tiene una orden asignada.');
    //     }

    //     $orden = Orden::where('id', $orden->id)
    //         ->lockForUpdate()
    //         ->with(['detalles.producto.categoria', 'detalles.estacion'])
    //         ->firstOrFail();

    //     $otroPuesto = PuestoEstacion::where('orden_id', $orden->id)->lockForUpdate()->first();
    //     if ($otroPuesto) {
    //         throw new \RuntimeException('La orden ya está asignada a otro puesto.');
    //     }

    //     $detallesPendientes = $orden->detalles
    //         ->where('estacion_id', $puesto->estacion_id)
    //         ->filter(fn ($detalle) => $detalle->estado_cocina === 'pendiente' || $detalle->estado_cocina === null);

    //     if ($detallesPendientes->isEmpty()) {
    //         throw new \RuntimeException('La orden no tiene detalles pendientes para esta estación.');
    //     }

    //     $puesto->orden_id = $orden->id;
    //     $puesto->save();

    //     OrdenDetalle::where('orden_id', $orden->id)
    //         ->where('estacion_id', $puesto->estacion_id)
    //         ->where(function ($query) {
    //             $query->where('estado_cocina', 'pendiente')
    //                   ->orWhereNull('estado_cocina');
    //         })
    //         ->update(['estado_cocina' => 'en_preparacion']);

    //     $orden->refresh();
    //     $orden->estado = $orden->detalles()->where('estado_cocina', 'pendiente')->exists() ? 'preparando' : 'listo';
    //     $orden->save();

    //     $puesto->load(['usuario', 'orden.cliente', 'orden.mesa', 'orden.detalles.producto.categoria', 'orden.detalles.estacion']);

    //     return $puesto;
    // }

    private function asignarOrdenAlPuesto(PuestoEstacion $puesto, Orden $orden): PuestoEstacion
{
    Log::info('========== INICIO asignarOrdenAlPuesto ==========', [
        'puesto_id' => $puesto->id,
        'orden_id' => $orden->id,
    ]);

    if ($puesto->orden_id !== null) {
        Log::warning('El puesto ya tiene orden', [
            'puesto_id' => $puesto->id,
            'orden_id' => $puesto->orden_id,
        ]);

        throw new \RuntimeException('El puesto ya tiene una orden asignada.');
    }

    Log::info('Buscando orden con lock', [
        'orden_id' => $orden->id,
    ]);

    $orden = Orden::where('id', $orden->id)
        ->lockForUpdate()
        ->with([
            'detalles.producto.categoria',
            'detalles.estacion'
        ])
        ->firstOrFail();

    Log::info('Orden encontrada', [
        'estado' => $orden->estado,
        'detalles' => $orden->detalles->count(),
    ]);

    $otroPuesto = PuestoEstacion::where('orden_id', $orden->id)
        ->lockForUpdate()
        ->first();

    if ($otroPuesto) {

        Log::warning('La orden ya pertenece a otro puesto', [
            'orden_id' => $orden->id,
            'puesto_id' => $otroPuesto->id,
        ]);

        throw new \RuntimeException('La orden ya está asignada a otro puesto.');
    }

    $detallesPendientes = $orden->detalles
        ->where('estacion_id', $puesto->estacion_id)
        ->filter(fn ($detalle) =>
            $detalle->estado_cocina === 'pendiente'
            || $detalle->estado_cocina === null
        );

    Log::info('Detalles pendientes encontrados', [
        'cantidad' => $detallesPendientes->count(),
        'detalles' => $detallesPendientes->map(function ($d) {
            return [
                'id' => $d->id,
                'producto' => $d->producto?->nombre,
                'estado' => $d->estado_cocina,
                'estacion' => $d->estacion_id,
            ];
        })->values()->all(),
    ]);

    if ($detallesPendientes->isEmpty()) {

        Log::warning('NO HAY DETALLES PENDIENTES');

        throw new \RuntimeException(
            'La orden no tiene detalles pendientes para esta estación.'
        );
    }

    Log::info('Asignando orden al puesto');

    $puesto->orden_id = $orden->id;

    $ok = $puesto->save();

    Log::info('Resultado save()', [
        'save' => $ok,
        'modelo_orden_id' => $puesto->orden_id,
    ]);

    $puesto->refresh();

    Log::info('Después del refresh()', [
        'orden_id_db' => $puesto->orden_id,
    ]);

    $actualizados = OrdenDetalle::where('orden_id', $orden->id)
        ->where('estacion_id', $puesto->estacion_id)
        ->where(function ($query) {
            $query->where('estado_cocina', 'pendiente')
                ->orWhereNull('estado_cocina');
        })
        ->update([
            'estado_cocina' => 'en_preparacion'
        ]);

    Log::info('Detalles actualizados', [
        'cantidad' => $actualizados,
    ]);

    $orden->refresh();

    $pendientes = $orden->detalles()
        ->where('estado_cocina', 'pendiente')
        ->exists();

    Log::info('¿Quedan pendientes?', [
        'pendientes' => $pendientes,
    ]);

    $orden->estado = $pendientes
        ? 'preparando'
        : 'listo';

    $orden->save();

    Log::info('Estado de la orden actualizado', [
        'estado' => $orden->estado,
    ]);

    $puesto->load([
        'usuario',
        'orden.cliente',
        'orden.mesa',
        'orden.detalles.producto.categoria',
        'orden.detalles.estacion'
    ]);

    Log::info('========== FIN asignarOrdenAlPuesto ==========', [
        'puesto_id' => $puesto->id,
        'orden_id_final' => $puesto->orden_id,
    ]);

    return $puesto;
}

    private function obtenerPuestoOcupadoSinOrden(int $estacionId): ?PuestoEstacion
    {
        return PuestoEstacion::where('estacion_id', $estacionId)
            ->whereNotNull('user_id')
            ->whereNull('orden_id')
            ->orderBy('nombre')
            ->first();
    }

    public function procesarNuevaOrden(Orden $orden): void
    {
        Log::info('========== procesarNuevaOrden ==========');
        if ($orden->esPreordenProgramada() || $orden->estado_preorden === 'cancelada') {
            return;
        }
        $estacionId = $orden->detalles()->where('estado_cocina', 'pendiente')->first()?->estacion_id;
        if (!$estacionId) {
            return;
        }

        $puesto = $this->obtenerPuestoOcupadoSinOrden($estacionId);
        if (!$puesto) {
            return;
        }

        try {
            $this->asignarParaPuesto($puesto);
        } catch (\Throwable $e) {
            Log::info('PuestoCocinaService: no se pudo asignar la orden nueva a un puesto libre ocupado', [
                'orden_id' => $orden->id,
                'puesto_id' => $puesto->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
