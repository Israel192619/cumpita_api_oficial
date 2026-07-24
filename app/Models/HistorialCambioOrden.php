<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistorialCambioOrden extends Model
{
    protected $table = 'historial_cambios_orden';

    protected $fillable = [
        'orden_id',
        'orden_detalle_id',
        'user_id',
        'producto_id',
        'tipo_cambio',
        'cantidad_anterior',
        'cantidad_nueva',
        'datos_anterior',
        'datos_nuevo',
    ];

    protected $casts = [
        'datos_anterior' => 'array',
        'datos_nuevo' => 'array',
        'cantidad_anterior' => 'integer',
        'cantidad_nueva' => 'integer',
    ];

    public function orden()
    {
        return $this->belongsTo(Orden::class);
    }

    public function detalle()
    {
        return $this->belongsTo(OrdenDetalle::class, 'orden_detalle_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}
