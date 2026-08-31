<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReservaStockActualizadaEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public function __construct(public array $productoIds) {}
    public function broadcastOn(): array { return [new Channel('canal-inventario')]; }
    public function broadcastAs(): string { return 'ReservaStockActualizada'; }
    public function broadcastWith(): array { return ['producto_ids' => $this->productoIds]; }
}
