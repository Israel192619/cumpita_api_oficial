<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Caja extends Model
{
    protected $table = 'cajas';

    protected $fillable = [
        'user_id',
        'monto_apertura',
        'monto_esperado',
        'monto_cierre',
        'diferencia',
        'fecha_apertura',
        'fecha_cierre',
        'estado',
        'observacion_apertura',
        'observacion_cierre',
    ];

    protected $casts = [
        'monto_apertura' => 'decimal:2',
        'monto_esperado' => 'decimal:2',
        'monto_cierre' => 'decimal:2',
        'diferencia' => 'decimal:2',
        'fecha_apertura' => 'datetime',
        'fecha_cierre' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function pagos()
    {
        return $this->hasMany(PagoOrden::class);
    }
}
