<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoCombinacion extends Model
{
    protected $table = 'producto_combinaciones';

    protected $fillable = ['producto_id', 'nombre', 'activo', 'predeterminada', 'orden'];

    protected $casts = [
        'activo' => 'boolean',
        'predeterminada' => 'boolean',
        'orden' => 'integer',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function opciones()
    {
        return $this->belongsToMany(
            ModificadorOpcion::class,
            'producto_combinacion_opciones',
            'producto_combinacion_id',
            'modificador_opcion_id'
        );
    }
}
