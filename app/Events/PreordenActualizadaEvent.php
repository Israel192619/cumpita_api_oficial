<?php

namespace App\Events;

use App\Models\Orden;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PreordenActualizadaEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $ordenId;
    public string $tipo;

    public function __construct(Orden $orden, string $tipo = 'preorden_actualizada')
    {
        $this->ordenId = (int) $orden->getKey();
        $this->tipo = $tipo;
    }

    public function broadcastOn(): array
    {
        return [new Channel('canal-ordenes')];
    }

    public function broadcastAs(): string
    {
        return 'PreordenActualizada';
    }

    public function broadcastWith(): array
    {
        return ['tipo' => $this->tipo, 'orden_id' => $this->ordenId];
    }
}
