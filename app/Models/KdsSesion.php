<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KdsSesion extends Model
{
    protected $table = 'kds_sesiones';

    protected $fillable = ['user_id', 'estacion_id', 'color', 'ultima_actividad'];

    protected $casts = ['ultima_actividad' => 'datetime'];

    public function usuario() { return $this->belongsTo(User::class, 'user_id'); }
    public function estacion() { return $this->belongsTo(EstacionTrabajo::class, 'estacion_id'); }
}
