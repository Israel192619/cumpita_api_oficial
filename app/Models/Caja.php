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

    /** Usuarios autorizados a cobrar en esta caja física. */
    public function usuarios()
    {
        return $this->belongsToMany(User::class, 'caja_usuarios')
            ->withPivot('asignado_por')
            ->withTimestamps();
    }

    public function estaDisponiblePara(int $userId): bool
    {
        return (int) $this->user_id === $userId || $this->usuarios()->whereKey($userId)->exists();
    }

    public function pagos()
    {
        return $this->hasMany(PagoOrden::class);
    }

    public function movimientos()
    {
        return $this->hasMany(MovimientoCaja::class);
    }

    public function gastos()
    {
        return $this->hasMany(GastoCaja::class);
    }
}
