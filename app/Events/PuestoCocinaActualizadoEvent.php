<?php

namespace App\Events;

use App\Models\PuestoEstacion;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PuestoCocinaActualizadoEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public PuestoEstacion $puesto)
    {
        $this->puesto->loadMissing([
            'usuario',
            'orden.detalles.producto.categoria',
            'orden.detalles.estacion',
        ]);
    }

    public function broadcastOn(): array
    {
        return [new Channel('canal-ordenes')];
    }

    public function broadcastAs(): string
    {
        return 'PuestoCocinaActualizado';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->puesto->id,
            'puesto_id' => $this->puesto->id,
            'nombre' => $this->puesto->nombre,
            'estacion_id' => $this->puesto->estacion_id,
            'ocupado' => $this->puesto->user_id !== null,
            'user_id' => $this->puesto->user_id,
            'user_nombre' => $this->puesto->usuario?->name,
            'orden_id' => $this->puesto->orden_id,
            'orden_estado_cocina' => $this->puesto->orden_estado_cocina,
            'orden_numero' => $this->puesto->orden?->numero_orden,
            'orden' => $this->puesto->orden?->toArray(),
        ];
    }
}
