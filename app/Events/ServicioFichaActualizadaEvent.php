<?php

namespace App\Events;

use App\Models\Orden;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServicioFichaActualizadaEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $ordenId,
        public string $accion,
        public ?int $meseroId,
        public ?array $ficha = null,
        public ?array $actividad = null,
    ) {}

    public static function desdeOrden(Orden $orden, string $accion, ?array $ficha = null): self
    {
        return new self((int) $orden->id, $accion, $orden->mesero_id ? (int) $orden->mesero_id : null, $ficha);
    }

    public function broadcastOn(): array
    {
        return [new Channel('canal-ordenes')];
    }

    public function broadcastAs(): string
    {
        return 'ServicioFichaActualizada';
    }

    public function broadcastWith(): array
    {
        return [
            'orden_id' => $this->ordenId,
            'accion' => $this->accion,
            'mesero_id' => $this->meseroId,
            'ficha' => $this->ficha,
            'actividad' => $this->actividad,
        ];
    }
}
