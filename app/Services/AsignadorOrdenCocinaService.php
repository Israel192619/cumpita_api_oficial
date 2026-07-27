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
            ->lockForUpdate()
            ->get();

        if ($puestosLibres->isEmpty()) {
            Log::info('AsignadorOrdenCocinaService: no hay puestos libres para asignar');
            return;
        }

        Log::info('AsignadorOrdenCocinaService: puestos libres encontrados', ['puestos' => $puestosLibres->pluck('id')->all()]);

        // Obtener órdenes pendientes que tengan detalles para cocina
        $ordenesPendientes = Orden::with(['detalles.producto', 'detalles.estacion', 'cliente', 'mesa'])
            ->whereDate('created_at', now()->toDateString())
            ->whereHas('detalles', function ($q) use ($cocina) {
                $q->where('estacion_id', $cocina->id)
                    ->where('estado_cocina', 'pendiente');
            })
            ->get();

        if ($ordenesPendientes->isEmpty()) {
            Log::info('AsignadorOrdenCocinaService: no hay ordenes pendientes para cocina');
            return;
        }

        // Para cada puesto libre, intentar asignar dentro de una transacción
        $asignaciones = [];
        foreach ($puestosLibres as $puesto) {
            $resultado = DB::transaction(function () use ($puesto) {
                return $this->asignarParaPuesto($puesto);
            });
            if ($resultado && is_array($resultado) && isset($resultado['puesto']) && isset($resultado['orden'])) {
                Log::info('AsignadorOrdenCocinaService: orden asignada', ['puesto_id' => $resultado['puesto']->id, 'orden_id' => $resultado['orden']->id]);
            }

            if ($resultado && is_array($resultado) && isset($resultado['puesto']) && isset($resultado['orden'])) {
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
        if (!$puesto) return null;
        if ($puesto->user_id === null) return $puesto; // no ocupado
        if ($puesto->orden_id !== null) return $puesto; // ya tiene orden

        // Obtener órdenes pendientes para la estación específica
        $ordenes = Orden::with(['detalles.producto', 'detalles.estacion', 'cliente', 'mesa'])
            ->whereDate('created_at', now()->toDateString())
            ->whereHas('detalles', function ($q) use ($cocinaId) {
                $q->where('estacion_id', $cocinaId)
                    ->where('estado_cocina', 'pendiente');
            })
            ->get();

        if ($ordenes->isEmpty()) {
            return $puesto;
        }

        // Calcular prioridad para cada orden
        $scored = $ordenes->map(function ($orden) use ($cocinaId) {
            return [
                'orden' => $orden,
                'score' => $this->calcularPrioridad($orden, $cocinaId),
            ];
        })->sortByDesc('score')->values();

        $top = $scored->first();
        if (!$top) {
            return $puesto;
        }

        $orden = Orden::where('id', $top['orden']->id)->lockForUpdate()->with('detalles')->first();

        if (!$orden) {
            Log::warning('AsignadorOrdenCocinaService: no se encontró la orden seleccionada', ['orden_id' => $top['orden']->id]);
            return $puesto;
        }

        // Verificar que no esté asignada a otro puesto
        $otro = PuestoEstacion::where('orden_id', $orden->id)->lockForUpdate()->first();
        if ($otro) {
            Log::info('AsignadorOrdenCocinaService: la orden ya está asignada a otro puesto', ['orden_id' => $orden->id, 'puesto_id' => $otro->id]);
            return $puesto;
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

    private function calcularPrioridad(Orden $orden, int $estacionId): float
    {
        // Factores: espera 40%, tiempo preparación 20%, complejidad 15%, dependencias 15%, tipo 5%, manual 5%
        $pesoEspera = 0.40;
        $pesoPrep = 0.20;
        $pesoComp = 0.15;
        $pesoDep = 0.15;
        $pesoTipo = 0.05;
        $pesoManual = 0.05;

        $now = now();
        $esperaMin = $orden->created_at->diffInMinutes($now);

        // Tiempo espera -> mapa a 0-100
        if ($esperaMin <= 5) $esperaPts = 5;
        elseif ($esperaMin <= 15) $esperaPts = 15;
        elseif ($esperaMin <= 30) $esperaPts = 30;
        else $esperaPts = 50;

        // Tiempo de preparación estimado (heurística)
        $prepMin = 0;
        foreach ($orden->detalles as $detalle) {
            $nombre = strtolower($detalle->producto?->nombre ?? '');
            $base = 5;
            if (str_contains($nombre, 'pescado') || str_contains($nombre, 'carne') || str_contains($nombre, 'parrilla')) {
                $base = 25;
            } elseif (str_contains($nombre, 'arroz') || str_contains($nombre, 'guarnici')) {
                $base = 10;
            } elseif (str_contains($nombre, 'sopa') || str_contains($nombre, 'caldo')) {
                $base = 5;
            }
            $prepMin += $base * max(1, (int) $detalle->cantidad);
        }

        // Map prepMin to 0-100 (caps)
        $prepPts = min(100, (int) ($prepMin));

        // Complejidad: cantidad de productos, detalles y estaciones involucradas
        $productosCount = $orden->detalles->sum(fn($d) => (int) $d->cantidad);
        $detallesCount = $orden->detalles->count();
        $estacionesCount = $orden->detalles->pluck('estacion_id')->unique()->count();
        $compPts = min(100, ($productosCount * 2) + ($detallesCount * 3) + ($estacionesCount * 5));

        // Dependencias: proporción de otros estaciones ya listos
        $otros = $orden->detalles->filter(fn($d) => $d->estacion_id !== $estacionId);
        $depPts = 0;
        if ($otros->isNotEmpty()) {
            $listos = $otros->filter(fn($d) => in_array($d->estado_cocina, ['listo_para_recoger', 'recogido', 'servido']))->count();
            $depPts = (int) (100 * ($listos / $otros->count()));
        }

        // Tipo de orden
        $tipo = $orden->tipo_orden ?? 'dine-in';
        $tipoPts = match ($tipo) {
            'dine-in' => 100,
            'to-go' => 70,
            'delivery' => 50,
            default => 50,
        };

        // Prioridad manual si existe (campo optional)
        $manual = $orden->prioridad ?? 0;
        $manualPts = min(100, (int) $manual);

        // Normalizar components to 0-1 then weight
        $score = ($esperaPts / 100) * $pesoEspera
               + ($prepPts / 100) * $pesoPrep
               + ($compPts / 100) * $pesoComp
               + ($depPts / 100) * $pesoDep
               + ($tipoPts / 100) * $pesoTipo
               + ($manualPts / 100) * $pesoManual;

        // Return scaled score
        return $score;
    }
}
