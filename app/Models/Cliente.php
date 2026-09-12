<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    protected $fillable = [
        'nombre', 'telefono', 'telefono_normalizado', 'direccion',
        'referencia_ubicacion', 'latitud', 'longitud', 'foto_local',
    ];

    protected $appends = ['foto_local_url'];

    protected $casts = ['latitud' => 'float', 'longitud' => 'float'];

    public function getFotoLocalUrlAttribute(): ?string
    {
        return $this->foto_local ? asset('storage/'.$this->foto_local) : null;
    }
}
