<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CajaActualizadaEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $cajaId, public string $accion) {}

    public function broadcastOn(): array
    {
        return [new Channel('canal-caja')];
    }

    public function broadcastAs(): string
    {
        return 'CajaActualizada';
    }

    public function broadcastWith(): array
    {
        return ['caja_id' => $this->cajaId, 'accion' => $this->accion];
    }
}
