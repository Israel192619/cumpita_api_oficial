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
        $accion = $ultimo?->datos_nuevo['accion'] ?? null;
        $apoyoVencido = $accion === 'llevar_apoyo' && $ultimo?->created_at?->lt(now()->subMinutes(2));
        $llevando = in_array($accion, ['llevar', 'llevar_apoyo'], true) && !$apoyoVencido;
        $entregado = ($ultimo?->datos_nuevo['accion'] ?? null) === 'entregar';

        return [
            'llevando_por_id' => $llevando ? $ultimo->user_id : null,
            'llevando_por' => $llevando ? ($ultimo->user?->name ?? 'Mesero') : null,
            'llevando_hasta' => $llevando && $accion === 'llevar_apoyo' ? $ultimo->created_at->copy()->addMinutes(2)->toIso8601String() : null,
            'es_apoyo' => $llevando && $accion === 'llevar_apoyo',
            'entregado_por' => $entregado ? ($ultimo->user?->name ?? 'Mesero') : null,
            'entregado_por_id' => $entregado ? $ultimo->user_id : null,
            'agregado_por' => $agregado?->user?->name,
            'servido' => $entregado || $detalle->estado_cocina === 'servido',
        ];
    }

    public function registrar(OrdenDetalle $detalle, int $usuarioId, string $accion, ?array $datosAnteriores = null): void
    {
        HistorialCambioOrden::create([
            'orden_id' => $detalle->orden_id,
            'orden_detalle_id' => $detalle->id,
            'producto_id' => $detalle->producto_id,
            'user_id' => $usuarioId,
            'tipo_cambio' => 'estado_cambiado',
            'datos_anterior' => $datosAnteriores,
            'datos_nuevo' => ['origen' => 'colaboracion_servicio', 'accion' => $accion],
        ]);
        $detalle->unsetRelation('historialCambios');
    }
}
