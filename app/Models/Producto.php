<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    protected $fillable = [
        'categoria_id', 
        'estacion_id',
        'nombre', 
        'descripcion', 
        'precio', 
        'imagen', 
        'activo', 
        'maneja_stock', 
        'stock', 
        'stock_minimo'
    ];
    protected $appends = ['imagen_url', 'modificadores_estructurados'];

    protected $casts = [
        'precio'       => 'decimal:2',
        'activo'       => 'boolean',
        'maneja_stock' => 'boolean',
        'stock'        => 'integer',
        'stock_minimo' => 'integer',
    ];

    public function categoria()
    {
        return $this->belongsTo(Categoria::class);
    }

    public function estacion()
    {
        return $this->belongsTo(EstacionTrabajo::class, 'estacion_id');
    }

    public function historialCambiosOrden()
    {
        return $this->hasMany(HistorialCambioOrden::class, 'producto_id');
    }

    public function ajustesStock()
    {
        return $this->hasMany(AjusteStock::class);
    }

    // Relación directa a las opciones asignadas
    public function opciones()
    {
        return $this->belongsToMany(ModificadorOpcion::class, 'producto_opciones', 'producto_id', 'modificador_opcion_id')
                    ->withPivot('predeterminado');
    }

    public function configuracionesModificador()
    {
        return $this->hasMany(ProductoModificadorConfiguracion::class);
    }

    public function combinaciones()
    {
        return $this->hasMany(ProductoCombinacion::class)
            ->orderBy('orden')
            ->orderBy('id');
    }

    // Relación dinámica para obtener los modificadores únicos estructurados
    // public function getModificadoresEstructuradosAttribute()
    // {
    //     return $this->opciones()
    //         ->with('modificador')
    //         ->where('modificador_opciones.activo', true) // Solo opciones activas en el POS
    //         ->get()
    //         ->groupBy('modificador_id')
    //         ->map(function ($opciones) {
    //             $modificador = $opciones->first()->modificador;
    //             return [
    //                 'id' => $modificador->id,
    //                 'nombre' => $modificador->nombre,
    //                 'tipo' => $modificador->tipo,
    //                 'requerido' => $modificador->requerido,
    //                 'opciones' => $opciones->map(function ($opc) {
    //                     return [
    //                         'id' => $opc->id,
    //                         'nombre' => $opc->nombre,
    //                         'precio_extra' => $opc->precio_extra,
    //                         'activo' => $opc->activo,
    //                         'predeterminado' => (bool) $opc->pivot->predeterminado
    //                     ];
    //                 })->values()
    //             ];
    //         })->values();
    // }

    public function getModificadoresEstructuradosAttribute()
    {
        // Usamos el método 'relationLoaded' para asegurarnos de que las opciones estén disponibles
        // Si no se precargaron con eager loading, las cargamos en este momento de forma segura
        if (!$this->relationLoaded('opciones')) {
            $this->load(['opciones' => function($query) {
                $query->where('modificador_opciones.activo', true)->with('modificador');
            }]);
        }

        if (!$this->relationLoaded('configuracionesModificador')) $this->load('configuracionesModificador');
        $configuraciones = $this->configuracionesModificador->keyBy('modificador_id');

        return $this->opciones
            ->groupBy('modificador_id')
            ->map(function ($opciones) use ($configuraciones) {
                $primerElemento = $opciones->first();
                
                // Validación por si acaso una opción se quedó sin grupo asignado
                $modificador = $primerElemento ? $primerElemento->modificador : null;
                if (!$modificador || !$modificador->activo) return null;

                return [
                    'id' => $modificador->id,
                    'nombre' => $modificador->nombre,
                    'color_fondo' => $modificador->color_fondo,
                    'tipo' => $modificador->tipo,          // 'unico' o 'multiple'
                    'requerido' => (bool) $modificador->requerido,
                    'cantidad_requerida' => $configuraciones->get($modificador->id)?->cantidad_requerida,
                    'cantidad_es_maxima' => $modificador->usaLimiteMaximo() || (bool) ($configuraciones->get($modificador->id)?->cantidad_es_maxima),
                    'opciones' => $opciones->filter(fn ($opc) => $opc->activo)->map(function ($opc) {
                        return [
                            'id' => $opc->id,
                            'nombre' => $opc->nombre,
                            'precio_extra' => $opc->precio_extra,
                            'activo' => $opc->activo,
                            'imagen_url' => $opc->imagen_url,
                            'mostrar_imagen' => (bool) $opc->mostrar_imagen,
                            'maneja_stock' => $opc->maneja_stock,
                            'stock' => $opc->stock,
                            'stock_minimo' => $opc->stock_minimo,
                            'stock_disponible' => $opc->maneja_stock && $opc->stock !== null ? max(0, (int) $opc->stock) : null,
                            'predeterminado' => (bool) $opc->pivot->predeterminado // Tu lógica de pivote funciona perfecto
                        ];
                    })->values()
                ];
            })
            ->filter() // Elimina posibles nulos si hubo modificadores huérfanos
            ->values();
    }

    public function getImagenUrlAttribute()
    {
        return $this->imagen
            ? '/storage/' . ltrim($this->imagen, '/')
            : null;
    }
}
