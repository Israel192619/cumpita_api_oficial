<?php

namespace App\Services;

use App\Events\OrdenCocinaActualizadaEvent;
use App\Events\PuestoCocinaOrdenAsignadaEvent;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\PuestoEstacion;
use App\Models\EstacionTrabajo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio encargado de asignar automáticamente órdenes a puestos de cocina
 */
class AsignadorOrdenCocinaService
{
    public function __construct(
        private PrioridadOrdenCocinaService $prioridadService
    ) {}

    public function asignarSiguienteOrdenDisponible(): void
    {
        $cocina = EstacionTrabajo::where('codigo', 'COCINA')->first();
        if (!$cocina) {
            return;
        }

        // Obtener puestos libres en cocina
        $puestosLibres = PuestoEstacion::where('estacion_id', $cocina->id)
            ->whereNotNull('user_id')
            ->whereNull('orden_id')
            ->get();

        if ($puestosLibres->isEmpty()) {
            Log::info('AsignadorOrdenCocinaService: no hay puestos libres para asignar');
            return;
        }

        Log::info('AsignadorOrdenCocinaService: puestos libres encontrados', ['puestos' => $puestosLibres->pluck('id')->all()]);

        // Para cada puesto libre, intentar asignar dentro de una transacción
        $asignaciones = [];
        foreach ($puestosLibres as $puesto) {
            $resultado = DB::transaction(function () use ($puesto) {
                return $this->asignarParaPuesto($puesto);
            });

            if ($resultado && is_array($resultado) && isset($resultado['puesto']) && isset($resultado['orden'])) {
                Log::info('AsignadorOrdenCocinaService: orden asignada', ['puesto_id' => $resultado['puesto']->id, 'orden_id' => $resultado['orden']->id]);
                $asignaciones[] = $resultado;
            }
        }

        // Emitir eventos fuera de la transacción para asegurar que se hayan confirmado los cambios
        foreach ($asignaciones as $item) {
            $puestoAsignado = $item['puesto'];
            $ordenAsignada = $item['orden'];
            event(new PuestoCocinaOrdenAsignadaEvent($puestoAsignado));
            event(new OrdenCocinaActualizadaEvent($ordenAsignada));
        }
    }

    public function asignarParaPuesto(PuestoEstacion $puesto): ?array
    {
        $cocinaId = $puesto->estacion_id;

        // Re-obtener con lock
        $puesto = PuestoEstacion::where('id', $puesto->id)->lockForUpdate()->with('usuario', 'estacion', 'orden')->first();
        if (!$puesto) {
            return null;
        }

        if ($puesto->user_id === null || $puesto->orden_id !== null) {
            return null;
        }

        $intentos = 0;
        while ($intentos < 3) {
            $intentos++;
            $ordenPrioritaria = $this->prioridadService->obtenerSiguienteOrden($cocinaId);
            if (!$ordenPrioritaria) {
                return null;
            }

            $orden = Orden::where('id', $ordenPrioritaria->id)
                ->lockForUpdate()
                ->with(['detalles.producto.categoria', 'detalles.estacion'])
                ->first();

            if (!$orden) {
                Log::warning('AsignadorOrdenCocinaService: no se encontró la orden seleccionada', [
                    'orden_id' => $ordenPrioritaria->id,
                    'attempt' => $intentos,
                ]);
                continue;
            }

            $otro = PuestoEstacion::where('orden_id', $orden->id)->lockForUpdate()->first();
            if ($otro) {
                Log::info('AsignadorOrdenCocinaService: la orden ya está asignada a otro puesto, reintentando', [
                    'orden_id' => $orden->id,
                    'puesto_id' => $otro->id,
                    'attempt' => $intentos,
                ]);
                continue;
            }

            // Asignar orden al puesto (sin emitir eventos aquí)
            $puesto->orden_id = $orden->id;
            $puesto->save();

            // Marcar detalles de la estación como en_preparacion
            OrdenDetalle::where('orden_id', $orden->id)
                ->where('estacion_id', $puesto->estacion_id)
                ->where('estado_cocina', 'pendiente')
                ->update(['estado_cocina' => 'en_preparacion']);

            // Actualizar estado general de la orden
            $orden->refresh();
            $orden->estado = $orden->detalles()->where('estado_cocina', 'pendiente')->exists() ? 'preparando' : 'listo';
            $orden->save();

            // Devolver datos para emitir eventos fuera de la transacción
            $puesto->load('usuario', 'orden');
            return ['puesto' => $puesto, 'orden' => $orden];
        }

        Log::info('AsignadorOrdenCocinaService: no se pudo asignar ninguna orden después de varios intentos', [
            'puesto_id' => $puesto->id,
            'attempts' => $intentos,
        ]);

        return null;
    }
}
