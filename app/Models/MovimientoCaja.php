<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovimientoCaja extends Model
{
    protected $table = 'movimiento_cajas';
    protected $fillable = [
        'caja_id',
        'tipo',
        'monto',
        'motivo',
        'usuario_id',
        'estado',
        'anulado_por',
        'anulado_en',
        'motivo_anulacion',
    ];
    protected $casts = [
        'monto' => 'decimal:2',
        'anulado_en' => 'datetime',
    ];
    public function caja()
    {
        return $this->belongsTo(Caja::class);
    }
    public function usuario()
    {
        return $this->belongsTo(User::class);
    }
    public function anulador()
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }
}
