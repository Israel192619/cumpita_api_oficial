<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagoOrden extends Model
{
    protected $table = 'pagos_ordenes';

    protected $fillable = [
        'id_orden',
        'monto_recibido',
        'monto_pagado',
        'cambio_devuelto',
        'metodo_pago',
        'tipo_pago',
        'fecha_pago',
    ];

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'id_orden');
    }
}
