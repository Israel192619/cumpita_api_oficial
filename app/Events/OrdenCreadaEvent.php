<?php

namespace App\Events;

use App\Models\Orden;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrdenCreadaEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public Orden $orden;

    public function __construct(Orden $orden)
    {
        $this->orden = $orden->loadMissing([
            'cliente:id,nombre',
            'mesa:id,numero',
            'detalles.producto.categoria',
        ]);
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
     * El KDS recibe la orden completa, sin requerir una consulta adicional.
     */
    public function broadcastWith(): array
    {
        return ['orden' => $this->orden->toArray()];
    }
}
