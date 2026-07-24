<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Orden extends Model
{
    protected $table = 'ordenes'; 
    protected $fillable = [
        'user_id',
        'cliente_id',
        'mesa_id',
        'fecha_orden',
        'fecha_reserva',
        'numero_orden',
        'subtotal',
        'descuento',
        'total',
        'estado',
        'estado_pago',
        'observaciones',
        'tipo_orden'
    ];

    protected $casts = [
        'fecha_orden' => 'datetime',
        'fecha_reserva' => 'datetime',
        'subtotal' => 'decimal:2',
        'descuento' => 'decimal:2',
        'total' => 'decimal:2',
        'estado' => 'string',
        'estado_pago' => 'string',
        'tipo_orden' => 'string',
    ];

    protected $appends = ['cliente_nombre', 'cliente_telefono', 'saldo_pendiente'];

    /**
     * Relación con Usuario
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relación con Cliente
     */
    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * Relación con Mesa
     */
    public function mesa()
    {
        return $this->belongsTo(Mesa::class);
    }

    /**
     * Relación con Detalles de Orden
     */
    public function detalles()
    {
        return $this->hasMany(OrdenDetalle::class);
    }

    /**
     * Relación con Pagos de Orden
     */
    public function pagos()
    {
        return $this->hasMany(PagoOrden::class, 'id_orden');
    }

    /**
     * Accesores para datos del cliente
     */
    public function getClienteNombreAttribute()
    {
        return $this->cliente?->nombre;
    }

    public function getClienteTelefonoAttribute()
    {
        return $this->cliente?->telefono;
    }

    public function getSaldoPendienteAttribute()
    {
        $pagos = $this->relationLoaded('pagos') ? $this->pagos : $this->pagos()->get();
        $pagosTotales = $pagos->sum(function ($pago) {
            return (float) ($pago->monto_pagado ?? 0);
        });

        return max(0, (float) $this->total - $pagosTotales);
    }
}

