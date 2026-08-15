<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenDetalle extends Model
{
    protected static function booted(): void
    {
        static::saved(fn (OrdenDetalle $detalle) => app(\App\Services\KdsEstacionService::class)->sincronizarDetalle($detalle));
    }

    protected $table = 'orden_detalles'; 

    protected $fillable = [
        'orden_id',
        'producto_id',
        'estacion_id',
        'cantidad',
        'precio_unitario',
        'nota',
        'estado_cocina',
        'fecha_servido',
    ];

    protected $casts = [
        'cantidad' => 'integer',
        'precio_unitario' => 'decimal:2',
        'nota' => 'string',
        'fecha_servido' => 'datetime',
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

    /** Estación congelada cuando se creó el detalle; no depende de cambios posteriores del producto. */
    public function estacion()
    {
        return $this->belongsTo(EstacionTrabajo::class, 'estacion_id');
    }

    public function estadosEstacion()
    {
        return $this->hasMany(OrdenDetalleEstacion::class, 'orden_detalle_id');
    }

    public function historialCambios()
    {
        return $this->hasMany(HistorialCambioOrden::class, 'orden_detalle_id');
    }
}
