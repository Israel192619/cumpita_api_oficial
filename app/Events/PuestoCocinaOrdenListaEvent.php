<?php

namespace App\Events;

use App\Models\Orden;
use App\Models\PuestoEstacion;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PuestoCocinaOrdenListaEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PuestoEstacion $puesto,
        public Orden $orden,
        public string $nuevoEstado,
        public User $actor,
    ) {
        $this->puesto->loadMissing(['usuario:id,name', 'orden:id,numero_orden']);
    }

    public function broadcastOn(): array
    {
        return [new Channel('canal-ordenes')];
    }

    public function broadcastAs(): string
    {
        return 'PuestoCocinaOrdenLista';
    }

    public function broadcastWith(): array
    {
        return [
            'puesto' => [
                'id' => $this->puesto->id,
                'nombre' => $this->puesto->nombre,
                'estacion_id' => $this->puesto->estacion_id,
                'orden_id' => $this->puesto->orden_id,
                'orden_numero' => $this->puesto->orden?->numero_orden,
                'orden_estado_cocina' => $this->puesto->orden_estado_cocina,
                'ocupado' => $this->puesto->user_id !== null,
                'user_id' => $this->puesto->user_id,
                'user_nombre' => $this->puesto->usuario?->name,
            ],
            'orden_id' => $this->orden->id,
            'nuevo_estado' => $this->nuevoEstado,
            'actor' => [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
            ],
        ];
    }
}
