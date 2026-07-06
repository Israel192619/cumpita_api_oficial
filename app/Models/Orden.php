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
        'subtotal',
        'descuento',
        'total',
        'estado',
        'metodo_pago',
        'observaciones',
        'tipo_orden'
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'descuento' => 'decimal:2',
        'total' => 'decimal:2',
        'estado' => 'string',
        'metodo_pago' => 'string',
        'tipo_orden' => 'string',
    ];

    protected $appends = ['cliente_nombre', 'cliente_telefono'];

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
}

