<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    protected $fillable = [
        'categoria_id', 
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

    // Relación directa a las opciones asignadas
    public function opciones()
    {
        return $this->belongsToMany(ModificadorOpcion::class, 'producto_opciones', 'producto_id', 'modificador_opcion_id')
                    ->withPivot('predeterminado');
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

        return $this->opciones
            ->groupBy('modificador_id')
            ->map(function ($opciones) {
                $primerElemento = $opciones->first();
                
                // Validación por si acaso una opción se quedó sin grupo asignado
                $modificador = $primerElemento ? $primerElemento->modificador : null;
                if (!$modificador) return null;

                return [
                    'id' => $modificador->id,
                    'nombre' => $modificador->nombre,
                    'tipo' => $modificador->tipo,          // 'unico' o 'multiple'
                    'requerido' => $modificador->requerido,
                    'opciones' => $opciones->map(function ($opc) {
                        return [
                            'id' => $opc->id,
                            'nombre' => $opc->nombre,
                            'precio_extra' => $opc->precio_extra,
                            'activo' => $opc->activo,
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
            ? asset('storage/' . $this->imagen)
            : null;
    }
}
