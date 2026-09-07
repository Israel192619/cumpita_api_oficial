<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ConfiguracionActualizada implements ShouldBroadcastNow
{
    use Dispatchable;

    public function broadcastOn(): array
    {
        return [new Channel('canal-configuracion')];
    }

    public function broadcastAs(): string
    {
        return 'ConfiguracionActualizada';
    }

    public function broadcastWith(): array
    {
        return ['seccion' => 'pos'];
    }
}
