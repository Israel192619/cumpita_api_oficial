<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mesa extends Model
{
    protected $fillable = ['numero', 'capacidad', 'estado'];

    protected $casts = [
        'capacidad' => 'integer',
        'estado' => 'string',
    ];
}
