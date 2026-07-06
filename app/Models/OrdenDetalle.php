<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenDetalle extends Model
{
    protected $table = 'orden_detalles'; 

    protected $fillable = [
        'orden_id',
        'producto_id',
        'cantidad',
        'precio_unitario'
    ];

    protected $casts = [
        'cantidad' => 'integer',
        'precio_unitario' => 'decimal:2',
    ];

    /**
     * Relación con Orden
     */
    public function orden()
    {
        return $this->belongsTo(Orden::class);
    }

    /**
     * Relación con Producto
     */
    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * Relación con las opciones del detalle
     */
    public function opciones()
    {
        return $this->hasMany(OrdenDetalleOpcion::class);
    }
}
