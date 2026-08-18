<?php

namespace App\Services;

use App\Models\Orden;
use Illuminate\Support\Collection;

class PrioridadOrdenCocinaService
{
    public function obtenerColaPriorizada(int $estacionId): Collection
    {
        $ordenes = $this->obtenerOrdenesPendientesParaEstacion($estacionId);

        return $ordenes
            ->map(function (Orden $orden) use ($estacionId) {
                return [
                    'orden' => $orden,
                    'score' => $this->calcularPrioridad($orden, $estacionId),
                ];
            })
            ->sortByDesc('score')
            ->values()
            ->map(fn (array $item) => $item['orden']);
    }

    public function obtenerSiguienteOrden(int $estacionId): ?Orden
    {
        return $this->obtenerColaPriorizada($estacionId)->first();
    }

    private function obtenerOrdenesPendientesParaEstacion(int $estacionId): Collection
    {
        return Orden::with(['detalles.producto', 'detalles.estacion', 'cliente', 'mesa'])
            ->operativas()->deFechaOperativa(now()->toDateString())
            ->whereHas('detalles', function ($query) use ($estacionId) {
                $query->where('estacion_id', $estacionId)
                    ->where(function ($query) {
                        $query->where('estado_cocina', 'pendiente')
                              ->orWhereNull('estado_cocina');
                    });
            })
            ->get();
    }

    private function calcularPrioridad(Orden $orden, int $estacionId): int
    {
        $pesoEspera = 0.40;
        $pesoPrep = 0.20;
        $pesoComp = 0.15;
        $pesoDep = 0.15;
        $pesoTipo = 0.05;
        $pesoManual = 0.05;

        $esperaPts = $this->calcularTiempoEspera($orden);
        $prepPts = $this->calcularTiempoPreparacion($orden);
        $compPts = $this->calcularComplejidad($orden);
        $depPts = $this->calcularDependencias($orden, $estacionId);
        $tipoPts = $this->calcularTipoOrden($orden);
        $manualPts = $this->obtenerPrioridadManual($orden);

        $score = ($esperaPts / 100) * $pesoEspera
            + ($prepPts / 100) * $pesoPrep
            + ($compPts / 100) * $pesoComp
            + ($depPts / 100) * $pesoDep
            + ($tipoPts / 100) * $pesoTipo
            + ($manualPts / 100) * $pesoManual;

        return (int) round($score * 1000);
    }

    private function calcularTiempoEspera(Orden $orden): int
    {
        $now = now();
        $esperaMin = $orden->created_at?->diffInMinutes($now) ?? 0;

        if ($esperaMin <= 5) {
            return 5;
        }

        if ($esperaMin <= 15) {
            return 15;
        }

        if ($esperaMin <= 30) {
            return 30;
        }

        return 50;
    }

    private function calcularTiempoPreparacion(Orden $orden): int
    {
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

        return min(100, (int) $prepMin);
    }

    private function calcularComplejidad(Orden $orden): int
    {
        $productosCount = $orden->detalles->sum(fn ($detalle) => (int) $detalle->cantidad);
        $detallesCount = $orden->detalles->count();
        $estacionesCount = $orden->detalles->pluck('estacion_id')->unique()->count();

        return min(100, ($productosCount * 2) + ($detallesCount * 3) + ($estacionesCount * 5));
    }

    private function calcularDependencias(Orden $orden, int $estacionId): int
    {
        $otros = $orden->detalles->filter(fn ($detalle) => $detalle->estacion_id !== $estacionId);

        if ($otros->isEmpty()) {
            return 0;
        }

        $listos = $otros->filter(fn ($detalle) => in_array($detalle->estado_cocina, ['listo_para_recoger', 'recogido', 'servido']))->count();

        return (int) (100 * ($listos / $otros->count()));
    }

    private function calcularTipoOrden(Orden $orden): int
    {
        $tipo = $orden->tipo_orden ?? 'dine-in';

        return match ($tipo) {
            'dine-in' => 100,
            'to-go' => 70,
            'delivery' => 50,
            default => 50,
        };
    }

    private function obtenerPrioridadManual(Orden $orden): int
    {
        return min(100, (int) ($orden->prioridad ?? 0));
    }
}
