<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenDetalleOpcion extends Model
{
    protected static function booted(): void
    {
        $sincronizar = function (OrdenDetalleOpcion $opcion): void {
            $detalle = $opcion->detalleOrden()->first();
            if ($detalle) app(\App\Services\KdsEstacionService::class)->sincronizarDetalle($detalle);
        };
        static::saved($sincronizar);
        static::deleted($sincronizar);
    }

    protected $table = 'orden_detalle_opciones';

    protected $fillable = [
        'orden_detalle_id',
        'modificador_opcion_id',
        'precio_extra'
    ];

    protected $casts = [
        'precio_extra' => 'decimal:2',
    ];

    /**
     * Relación con Detalle de Orden
     */
    public function detalleOrden()
    {
        return $this->belongsTo(OrdenDetalle::class, 'orden_detalle_id');
    }

    /**
     * Relación con Opción del Modificador
     */
    public function modificadorOpcion()
    {
        return $this->belongsTo(ModificadorOpcion::class);
    }
}
