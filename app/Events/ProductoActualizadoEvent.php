<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ProductoActualizadoEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public array $producto) {}

    public function broadcastOn(): array { return [new Channel('canal-inventario')]; }
    public function broadcastAs(): string { return 'ProductoActualizado'; }
    public function broadcastWith(): array { return ['producto' => $this->producto]; }
}
