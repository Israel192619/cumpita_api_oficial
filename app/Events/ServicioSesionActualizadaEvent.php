<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServicioSesionActualizadaEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $tipo,
        public int $userId,
        public string $sessionId,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('canal-ordenes')];
    }

    public function broadcastAs(): string
    {
        return 'ServicioSesionActualizada';
    }

    public function broadcastWith(): array
    {
        return [
            'tipo' => $this->tipo,
            'user_id' => $this->userId,
            'session_id' => $this->sessionId,
        ];
    }
}
