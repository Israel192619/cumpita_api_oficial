<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstacionTrabajo extends Model
{
    protected $table = 'estaciones_trabajo';

    protected $fillable = ['nombre', 'codigo', 'descripcion', 'activa', 'orden'];

    protected $casts = [
        'activa' => 'boolean',
        'orden' => 'integer',
    ];

    public function productos()
    {
        return $this->hasMany(Producto::class, 'estacion_id');
    }

    public function modificadores()
    {
        return $this->hasMany(Modificador::class, 'estacion_id');
    }

    public function usuarios()
    {
        return $this->hasMany(User::class, 'estacion_id');
    }

    public function detallesOrden()
    {
        return $this->hasMany(OrdenDetalle::class, 'estacion_id');
    }

    public function estadosKds()
    {
        return $this->hasMany(OrdenDetalleEstacion::class, 'estacion_id');
    }

}
