<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Orden extends Model
{
    protected $table = 'ordenes'; 
    protected $fillable = [
        'user_id',
        'mesero_id',
        'tomada_en',
        'entregada_en',
        'cliente_id',
        'mesa_id',
        'fecha_orden',
        'fecha_programada',
        'tipo_flujo',
        'estado_preorden',
        'preorden_activada_en',
        'preorden_activada_por',
        'preorden_cancelada_en',
        'preorden_cancelada_por',
        'motivo_cancelacion_preorden',
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
        'fecha_programada' => 'datetime',
        'preorden_activada_en' => 'datetime',
        'preorden_cancelada_en' => 'datetime',
        'subtotal' => 'decimal:2',
        'descuento' => 'decimal:2',
        'total' => 'decimal:2',
        'estado' => 'string',
        'estado_pago' => 'string',
        'tipo_orden' => 'string',
        'tomada_en' => 'datetime',
        'entregada_en' => 'datetime',
    ];

    protected $appends = ['cliente_nombre', 'cliente_telefono', 'saldo_pendiente'];

    /**
     * Relación con Usuario
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function mesero()
    {
        return $this->belongsTo(User::class, 'mesero_id');
    }

    public function preordenActivadaPor()
    {
        return $this->belongsTo(User::class, 'preorden_activada_por');
    }

    public function preordenCanceladaPor()
    {
        return $this->belongsTo(User::class, 'preorden_cancelada_por');
    }

    public function scopeOperativas($query)
    {
        return $query->where(function ($query) {
            $query->whereIn('tipo_flujo', ['normal'])
                ->orWhereNull('tipo_flujo')
                ->orWhere(function ($query) {
                    $query->where('tipo_flujo', 'preorden')->where('estado_preorden', 'activada');
                });
        });
    }

    public function scopeDeFechaOperativa($query, string $fecha)
    {
        return $query->where(function ($query) use ($fecha) {
            $query->where(function ($query) use ($fecha) {
                $query->where('tipo_flujo', 'preorden')
                    ->where('estado_preorden', 'activada')
                    ->whereDate('preorden_activada_en', $fecha);
            })->orWhere(function ($query) use ($fecha) {
                $query->where(function ($query) {
                    $query->where('tipo_flujo', 'normal')->orWhereNull('tipo_flujo');
                })->whereDate('created_at', $fecha);
            });
        });
    }

    public function esPreordenProgramada(): bool
    {
        return $this->tipo_flujo === 'preorden' && $this->estado_preorden === 'programada';
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

    public function historialCambios()
    {
        return $this->hasMany(HistorialCambioOrden::class, 'orden_id');
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
