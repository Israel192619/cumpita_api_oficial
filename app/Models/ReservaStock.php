<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReservaStock extends Model
{
    protected $table = 'reservas_stock';
    protected $fillable = ['producto_id', 'usuario_id', 'sesion_id', 'cantidad', 'expira_en'];
    protected $casts = ['expira_en' => 'datetime'];

    public function scopeActivas($query) { return $query->where('expira_en', '>', now()); }
}
