<?php

namespace App\Services;

use App\Models\OrdenDetalle;
use App\Models\OrdenDetalleEstacion;
use Illuminate\Support\Collection;

class KdsEstacionService
{
    public function sincronizarDetalle(OrdenDetalle $detalle): void
    {
        $detalle->loadMissing(['producto', 'opciones.modificadorOpcion.modificador']);
        $estaciones = collect([$detalle->estacion_id])
            ->merge($detalle->opciones->map(
                fn ($opcion) => $opcion->modificadorOpcion?->modificador?->estacion_id
            ))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();

        foreach ($estaciones as $estacionId) {
            OrdenDetalleEstacion::firstOrCreate(
                ['orden_detalle_id' => $detalle->id, 'estacion_id' => $estacionId],
                ['estado' => $detalle->estado_cocina ?: 'pendiente']
            );
        }

        $detalle->estadosEstacion()->whereNotIn('estacion_id', $estaciones)->delete();
    }

    public function sincronizar(Collection $detalles): void
    {
        $detalles->each(fn (OrdenDetalle $detalle) => $this->sincronizarDetalle($detalle));
    }
}
