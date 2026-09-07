<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockActualizadoEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ?int $productoId,
        public int $stock,
        public ?int $modificadorOpcionId = null,
    ) {}

    public function broadcastOn(): array { return [new Channel('canal-inventario')]; }
    public function broadcastAs(): string { return 'StockActualizado'; }
    public function broadcastWith(): array
    {
        return [
            'producto_id' => $this->productoId,
            'modificador_opcion_id' => $this->modificadorOpcionId,
            'stock' => $this->stock,
        ];
    }
}
