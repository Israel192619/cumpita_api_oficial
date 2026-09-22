<?php

namespace App\Events;

use App\Models\Orden;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrdenCocinaActualizadaEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param array<int, int> $historialIds */
    public int $ordenId;

    public function __construct(Orden $orden, array $historialIds = [], public ?string $origen = null, public array $detalles = [])
    {
        $this->ordenId = (int) $orden->getKey();
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
        $payload = ['tipo' => 'orden_actualizada', 'orden_id' => $this->ordenId];
        if ($this->origen !== null) $payload['origen'] = $this->origen;
        if ($this->detalles) $payload['detalles'] = $this->detalles;
        return $payload;
    }
}
