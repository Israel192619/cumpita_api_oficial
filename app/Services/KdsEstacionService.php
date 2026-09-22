<?php

namespace App\Services;

use App\Models\OrdenDetalle;
use App\Models\OrdenDetalleEstacion;
use Illuminate\Support\Collection;

class KdsEstacionService
{
    public function sincronizarDetalle(OrdenDetalle $detalle): void
    {
        $detalle->loadMissing('orden');
        if ($detalle->orden?->origen_registro === 'cliente' && $detalle->orden?->estado_solicitud !== 'aceptada') {
            $detalle->estadosEstacion()->delete();
            return;
        }
        $detalle->loadMissing(['producto', 'opciones.modificadorOpcion.modificador']);
        foreach ($detalle->opciones as $opcion) {
            $modificador = $opcion->modificadorOpcion?->modificador;
            // Servicio puede cargar una proyección para presentación sin estación.
            // Nunca interpretar una columna no consultada como estación eliminada.
            if ($modificador && !array_key_exists('estacion_id', $modificador->getAttributes())) {
                $opcion->modificadorOpcion->load('modificador');
            }
        }
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
        // Leer relaciones en conjunto y reparar solo estaciones faltantes o sobrantes.
        // Un tablero ya sincronizado no necesita escrituras por cada producto.
        $modelos = new \Illuminate\Database\Eloquent\Collection($detalles->all());
        $modelos->loadMissing(['orden', 'opciones.modificadorOpcion.modificador', 'estadosEstacion']);
        $proyeccionIncompleta = $modelos->contains(fn ($detalle) => $detalle->opciones->contains(function ($opcion) {
            $modificador = $opcion->modificadorOpcion?->modificador;
            return $modificador && !array_key_exists('estacion_id', $modificador->getAttributes());
        }));
        if ($proyeccionIncompleta) $modelos->load('opciones.modificadorOpcion.modificador');
        foreach ($modelos as $detalle) {
            $esperadas = collect([$detalle->estacion_id])->merge($detalle->opciones->map(
                fn ($opcion) => $opcion->modificadorOpcion?->modificador?->estacion_id
            ))->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            if ($detalle->orden?->origen_registro === 'cliente' && $detalle->orden?->estado_solicitud !== 'aceptada') $esperadas = [];
            $actuales = $detalle->estadosEstacion->pluck('estacion_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            if ($actuales !== $esperadas) $this->sincronizarDetalle($detalle);
        }
    }
}
