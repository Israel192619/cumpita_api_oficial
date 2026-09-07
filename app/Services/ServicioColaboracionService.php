<?php

namespace App\Services;

use App\Models\HistorialCambioOrden;
use App\Models\OrdenDetalle;

class ServicioColaboracionService
{
    public function estado(OrdenDetalle $detalle): array
    {
        $historial = $detalle->historialCambios->sortByDesc('id');
        $ultimo = $historial->first(fn ($cambio) => ($cambio->datos_nuevo['origen'] ?? null) === 'colaboracion_servicio');
        $agregado = $historial->first(fn ($cambio) => ($cambio->datos_nuevo['origen'] ?? null) === 'servicio_mesero');
        $llevando = ($ultimo?->datos_nuevo['accion'] ?? null) === 'llevar';
        $entregado = ($ultimo?->datos_nuevo['accion'] ?? null) === 'entregar';

        return [
            'llevando_por_id' => $llevando ? $ultimo->user_id : null,
            'llevando_por' => $llevando ? ($ultimo->user?->name ?? 'Mesero') : null,
            'entregado_por' => $entregado ? ($ultimo->user?->name ?? 'Mesero') : null,
            'agregado_por' => $agregado?->user?->name,
            'servido' => $entregado || $detalle->estado_cocina === 'servido',
        ];
    }

    public function registrar(OrdenDetalle $detalle, int $usuarioId, string $accion): void
    {
        HistorialCambioOrden::create([
            'orden_id' => $detalle->orden_id,
            'orden_detalle_id' => $detalle->id,
            'producto_id' => $detalle->producto_id,
            'user_id' => $usuarioId,
            'tipo_cambio' => 'estado_cambiado',
            'datos_nuevo' => ['origen' => 'colaboracion_servicio', 'accion' => $accion],
        ]);
        $detalle->unsetRelation('historialCambios');
    }
}
