<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KdsAsignacion extends Model
{
    protected $table = 'kds_asignaciones';

    protected $fillable = ['orden_id', 'estacion_id', 'user_id', 'asignada_en'];

    protected $casts = ['asignada_en' => 'datetime'];

    public function usuario() { return $this->belongsTo(User::class, 'user_id'); }
    public function orden() { return $this->belongsTo(Orden::class, 'orden_id'); }
}
