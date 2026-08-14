<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GastoCaja extends Model
{
    protected $table = 'gastos_caja';

    protected $fillable = [
        'caja_id', 'usuario_id', 'categoria', 'concepto', 'monto', 'estado',
        'anulado_por', 'anulado_en', 'motivo_anulacion',
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
