<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoModificadorConfiguracion extends Model
{
    protected $table = 'producto_modificador_configuraciones';
    protected $fillable = ['producto_id', 'modificador_id', 'cantidad_requerida', 'cantidad_es_maxima'];
    protected $casts = ['cantidad_requerida' => 'integer', 'cantidad_es_maxima' => 'boolean'];
}
