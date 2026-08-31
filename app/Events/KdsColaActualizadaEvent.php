<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KdsColaActualizadaEvent implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function broadcastOn(): array { return [new Channel('canal-ordenes')]; }
    public function broadcastAs(): string { return 'KdsColaActualizada'; }
    public function broadcastWith(): array { return ['tipo' => 'kds_cola_actualizada']; }
}
