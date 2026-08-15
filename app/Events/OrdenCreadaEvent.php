<?php

namespace App\Events;

use App\Models\Orden;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrdenCreadaEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public int $ordenId;

    public function __construct(Orden $orden)
    {
        $this->ordenId = (int) $orden->getKey();
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('canal-ordenes'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'OrdenCreada';
    }

    /**
     * El consumidor vuelve a consultar su proyección; no se serializan relaciones.
     */
    public function broadcastWith(): array
    {
        return ['tipo' => 'orden_creada', 'orden_id' => $this->ordenId];
    }
}
