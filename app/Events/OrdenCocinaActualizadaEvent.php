<?php

namespace App\Events;

use App\Models\Orden;
use App\Models\HistorialCambioOrden;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrdenCocinaActualizadaEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param array<int, int> $historialIds */
    public function __construct(public Orden $orden, private array $historialIds = [])
    {
        $this->orden->loadMissing(['detalles.producto.categoria', 'detalles.estacion']);
    }

    public function broadcastOn(): array
    {
        return [new Channel('canal-ordenes')];
    }

    public function broadcastAs(): string
    {
        return 'OrdenCocinaActualizada';
    }

    public function broadcastWith(): array
    {
        $cambios = empty($this->historialIds)
            ? []
            : HistorialCambioOrden::query()
                ->whereIn('id', $this->historialIds)
                ->with('producto:id,nombre')
                ->orderBy('id')
                ->get()
                ->toArray();

        return [
            // Se envía únicamente la orden afectada, con sus detalles y categorías.
            // El cliente puede actualizar su signal sin recargar el tablero completo.
            'orden' => $this->orden->toArray(),
            'cambios' => $cambios,
        ];
    }
}
