<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PuestoEstacion extends Model
{
    protected $table = 'puestos_estacion';

    protected $fillable = [
        'estacion_id',
        'nombre',
        'user_id',
        'orden_id',
    ];

    protected $appends = ['orden_estado_cocina'];

    public function estacion()
    {
        return $this->belongsTo(EstacionTrabajo::class, 'estacion_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'orden_id');
    }

    public function getOrdenEstadoCocinaAttribute()
    {
        if ($this->orden && !$this->orden->relationLoaded('detalles')) {
    $this->orden->loadMissing('detalles');
}

        if (!$this->orden || $this->orden->detalles->isEmpty()) {
            return null;
        }

        $detalles = $this->orden->detalles->filter(function ($detalle) {
            return $detalle->estacion_id === $this->estacion_id;
        });

        if ($detalles->isEmpty()) {
            return null;
        }

        if ($detalles->contains(fn ($detalle) => $detalle->estado_cocina === 'pendiente')) {
            return 'pendiente';
        }

        if ($detalles->contains(fn ($detalle) => $detalle->estado_cocina === 'en_preparacion')) {
            return 'en_preparacion';
        }

        if ($detalles->contains(fn ($detalle) => $detalle->estado_cocina === 'listo_para_recoger')) {
            return 'listo_para_recoger';
        }

        if ($detalles->contains(fn ($detalle) => in_array($detalle->estado_cocina, ['recogido', 'servido']))) {
            return 'recogido';
        }

        return null;
    }
}
