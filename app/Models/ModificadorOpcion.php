<?php

namespace App\Models;

use App\Events\StockActualizadoEvent;
use Illuminate\Database\Eloquent\Model;

class ModificadorOpcion extends Model
{
    protected $table = 'modificador_opciones';
    protected $fillable = ['modificador_id', 'nombre', 'precio_extra', 'imagen', 'mostrar_imagen', 'activo', 'maneja_stock', 'stock', 'stock_minimo'];
    protected $appends = ['imagen_url'];
    protected $casts = [
        'precio_extra' => 'decimal:2',
        'activo' => 'boolean',
        'mostrar_imagen' => 'boolean',
        'maneja_stock' => 'boolean',
        'stock' => 'integer',
        'stock_minimo' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updated(function (self $opcion): void {
            if ($opcion->wasChanged('stock') && $opcion->maneja_stock && $opcion->stock !== null) {
                StockActualizadoEvent::dispatch(null, (int) $opcion->stock, (int) $opcion->id);
            }
        });
    }

    public function modificador()
    {
        return $this->belongsTo(Modificador::class);
    }

    public function getImagenUrlAttribute(): ?string
    {
        return $this->imagen ? '/storage/'.ltrim($this->imagen, '/') : null;
    }
}
