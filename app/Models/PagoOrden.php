<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagoOrden extends Model
{
    protected $table = 'pagos_ordenes';

    protected $fillable = [
        'id_orden',
        'caja_id',
        'user_id',
        'monto_recibido',
        'monto_pagado',
        'cambio_devuelto',
        'metodo_pago',
        'tipo_pago',
        'fecha_pago',
        'pago_origen_id',
        'caja_origen_id',
    ];

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'id_orden');
    }

    public function caja()
    {
        return $this->belongsTo(Caja::class);
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function pagoOrigen()
    {
        return $this->belongsTo(self::class, 'pago_origen_id');
    }

    public function cajaOrigen()
    {
        return $this->belongsTo(Caja::class, 'caja_origen_id');
    }
}
