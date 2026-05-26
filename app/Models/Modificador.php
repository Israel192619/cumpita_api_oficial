<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Modificador extends Model
{
    protected $table = 'modificadores';
    protected $fillable = ['nombre', 'tipo', 'requerido', 'activo'];

    public function opciones()
    {
        return $this->hasMany(ModificadorOpcion::class);
    }
}
