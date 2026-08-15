<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Modificador extends Model
{
    protected $table = 'modificadores';
    protected $fillable = ['nombre', 'estacion_id', 'tipo', 'requerido', 'activo'];

    protected $casts = [
        'requerido' => 'boolean',
        'activo' => 'boolean',
    ];

    public function estacion()
    {
        return $this->belongsTo(EstacionTrabajo::class, 'estacion_id');
    }

    public function opciones()
    {
        return $this->hasMany(ModificadorOpcion::class);
    }
}
