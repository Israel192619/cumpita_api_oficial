<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModificadorOpcion extends Model
{
    protected $table = 'modificador_opciones';
    protected $fillable = ['modificador_id', 'nombre', 'precio_extra', 'activo'];
    protected $casts = [
        'precio_extra' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function modificador()
    {
        return $this->belongsTo(Modificador::class);
    }
}
