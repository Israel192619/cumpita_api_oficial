<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Orden extends Model
{
    public static function siguienteNumeroParaFecha(string $fecha): int
    {
        $ultimo = static::query()
            ->where(function ($query) use ($fecha) {
                $query->whereDate('fecha_orden', $fecha)
                    ->orWhere(function ($query) use ($fecha) {
                        $query->whereNull('fecha_orden')->whereDate('created_at', $fecha);
                    });
            })
            ->orderByDesc('numero_orden')
            ->lockForUpdate()
            ->value('numero_orden');

        return (int) ($ultimo ?? 0) + 1;
    }

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
        'origen_registro',
        'estado_solicitud',
        'codigo_publico',
        'solicitud_revisada_por',
        'solicitud_revisada_en',
        'motivo_rechazo',
        'solicitud_expira_en',
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
        'version',
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
        'version' => 'integer',
        'solicitud_revisada_en' => 'datetime',
        'solicitud_expira_en' => 'datetime',
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

    public function solicitudRevisadaPor()
    {
        return $this->belongsTo(User::class, 'solicitud_revisada_por');
    }

    public function scopeOperativas($query)
    {
        return $query->where(fn ($query) => $query->whereNull('estado_solicitud')->orWhere('estado_solicitud', 'aceptada'))
            ->where(function ($query) {
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
                    ->where(function ($query) use ($fecha) {
                        // La ficha cambia de programada a operativa al activarse.
                        // fecha_orden se actualiza en ese mismo momento y sirve de
                        // respaldo si preorden_activada_en aún no está disponible
                        // en datos antiguos o durante una transición de despliegue.
                        $query->whereDate('preorden_activada_en', $fecha)
                            ->orWhereDate('fecha_orden', $fecha);
                    });
            })->orWhere(function ($query) use ($fecha) {
                $query->where(function ($query) {
                    $query->where('tipo_flujo', 'normal')->orWhereNull('tipo_flujo');
                })->where(function ($query) use ($fecha) {
                    $query->whereDate('fecha_orden', $fecha)
                        ->orWhere(function ($query) use ($fecha) {
                            $query->whereNull('fecha_orden')->whereDate('created_at', $fecha);
                        });
                });
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

    public function cambiosMesero()
    {
        return $this->historialCambios()
            ->where('tipo_cambio', 'detalle_agregado')
            ->whereHas('user.role', fn ($query) => $query->whereRaw('LOWER(nombre) = ?', ['mesero']));
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
        // Una venta cancelada quedó saldada mediante su devolución. La suma neta
        // de pagos vuelve a cero, pero eso no significa que deba cobrarse otra vez.
        if ($this->estado === 'cancelado') {
            return 0;
        }

        $pagos = $this->relationLoaded('pagos') ? $this->pagos : $this->pagos()->get();
        $pagosTotales = $pagos->sum(function ($pago) {
            return (float) ($pago->monto_pagado ?? 0);
        });

        return max(0, (float) $this->total - $pagosTotales);
    }
}
