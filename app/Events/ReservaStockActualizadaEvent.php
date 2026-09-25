<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReservaStockActualizadaEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public function __construct(public array $productoIds = [], public array $modificadorOpcionIds = []) {}
    public function broadcastOn(): array { return [new Channel('canal-inventario')]; }
    public function broadcastAs(): string { return 'ReservaStockActualizada'; }
    public function broadcastWith(): array
    {
        return [
            'producto_ids' => $this->productoIds,
            'modificador_opcion_ids' => $this->modificadorOpcionIds,
        ];
    }
}
