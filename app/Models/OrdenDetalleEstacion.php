<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenDetalleEstacion extends Model
{
    protected $table = 'orden_detalle_estaciones';

    protected $fillable = ['orden_detalle_id', 'estacion_id', 'estado', 'fecha_servido'];

    protected $casts = ['fecha_servido' => 'datetime'];

    public function detalle()
    {
        return $this->belongsTo(OrdenDetalle::class, 'orden_detalle_id');
    }

    public function estacion()
    {
        return $this->belongsTo(EstacionTrabajo::class, 'estacion_id');
    }
}
