<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mesa extends Model
{
    protected $fillable = ['numero', 'capacidad', 'estado', 'posicion_x', 'posicion_y'];

    protected $casts = [
        'capacidad' => 'integer',
        'estado' => 'string',
        'posicion_x' => 'float',
        'posicion_y' => 'float',
    ];
}
