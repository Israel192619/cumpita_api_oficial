<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\AjusteStock;
use App\Events\StockActualizadoEvent;
use App\Models\ReservaStock;
use App\Models\ReservaStockModificador;
use App\Models\Producto;
use App\Models\ProductoModificadorConfiguracion;
use App\Models\ProductoCombinacion;
use App\Models\Modificador;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // OPTIMIZACIÓN: Añadimos 'opciones.modificador' al método 'with'
        $query = Producto::with(['categoria', 'estacion', 'opciones.modificador', 'configuracionesModificador', 'combinaciones.opciones']);

        if ($request->filled('categoria_id')) {
            $categoriaId = $request->categoria_id;

            $idsCategorias = Categoria::where('parent_id', $categoriaId)
                ->pluck('id')
                ->toArray();

            $idsCategorias[] = (int) $categoriaId;

            $query->whereIn('categoria_id', $idsCategorias);
        }

        // Traemos los productos de la base de datos de forma eficiente
        $productos = $query->latest()->get();

        // Asignamos el atributo estructurado
        $productos->transform(function ($producto) {
            // Al usar el accesor corregido que te di antes, esto usará los datos en memoria
            $producto->modificadores = $producto->modificadores_estructurados;
            
            // Opcional: Si no quieres enviar la relación plana 'opciones' en el JSON para ahorrar datos, puedes ocultarla aquí:
            unset($producto->opciones); 
            
            return $producto;
        });

        $sesionId = $request->input('reserva_sesion');
        $reservas = ReservaStock::activas()->selectRaw('producto_id, SUM(cantidad) as cantidad')
            ->when($sesionId, fn ($query) => $query->where('sesion_id', '!=', $sesionId))
            ->groupBy('producto_id')->pluck('cantidad', 'producto_id');
        $opcionIds = $productos->flatMap(fn ($producto) => collect($producto->modificadores)
            ->flatMap(fn ($grupo) => collect($grupo['opciones'] ?? [])->pluck('id')))->unique()->values();
        $reservasOpciones = ReservaStockModificador::activas()
            ->selectRaw('modificador_opcion_id, SUM(cantidad) as cantidad')
            ->when($sesionId, fn ($query) => $query->where('sesion_id', '!=', $sesionId))
            ->whereIn('modificador_opcion_id', $opcionIds)
            ->groupBy('modificador_opcion_id')->pluck('cantidad', 'modificador_opcion_id');
        $productos->each(function ($producto) use ($reservas, $reservasOpciones) {
            $producto->stock_disponible = $producto->maneja_stock && $producto->stock !== null
                ? max(0, (int) $producto->stock - (int) ($reservas[$producto->id] ?? 0))
                : null;
            $producto->modificadores = collect($producto->modificadores)->map(function ($grupo) use ($reservasOpciones) {
                $grupo['opciones'] = collect($grupo['opciones'] ?? [])->map(function ($opcion) use ($reservasOpciones) {
                    if ($opcion['maneja_stock'] && $opcion['stock'] !== null) {
                        $opcion['stock_disponible'] = max(0, (int) $opcion['stock'] - (int) ($reservasOpciones[$opcion['id']] ?? 0));
                    }
                    return $opcion;
                })->values();
                return $grupo;
            })->values();
        });

        return response()->json([
            'productos' => $productos,
            'message' => 'Productos obtenidos correctamente'
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
  public function store(Request $request)
    {
        $data = $this->validarProducto($request);
        $categoria = Categoria::with('children')
            ->findOrFail($data['categoria_id']);
        if ($categoria->children->count() > 0) {
            return response()->json([
                'message' => 'Debe seleccionar una subcategoría válida.'
            ], 422);
        }
        return DB::transaction(function () use ($data, $request) {
            $imagenPath = null;
            if ($request->hasFile('imagen')) {
                $imagenPath = $request->file('imagen')->store('productos', 'public');
            }

            $producto = Producto::create([
                'categoria_id' => $data['categoria_id'],
                'estacion_id'  => $data['estacion_id'],
                'nombre'       => $data['nombre'],
                'descripcion'  => $data['descripcion'] ?? null,
                'precio'       => $data['precio'],
                'activo'       => $data['activo'] ?? true,
                'maneja_stock' => $data['maneja_stock'] ?? false,
                'stock'        => $data['stock'] ?? null,
                'stock_minimo' => $data['stock_minimo'] ?? null,
                'imagen'       => $imagenPath,
            ]);

            if (!empty($data['opciones'])) {
                $opcionesSync = [];
                foreach ($data['opciones'] as $opcion) {
                    $opcionesSync[$opcion['id']] = [
                        'predeterminado' => $opcion['predeterminado'] ?? false
                    ];
                }
                $producto->opciones()->sync($opcionesSync);
            }
            $this->sincronizarConfiguraciones($producto, $data['modificadores'] ?? []);
            $this->sincronizarCombinaciones($producto, $data['combinaciones'] ?? [], collect($data['opciones'] ?? [])->pluck('id')->all());
            return response()->json([
                'producto' => $producto->load(['categoria', 'estacion', 'combinaciones.opciones']),
                'message'  => 'Producto creado correctamente'
            ], 201);
        });
    }

    /**
     * Display the specified resource.
     */
    public function show(Producto $producto)
    {
        $producto->load(['opciones.modificador', 'configuracionesModificador', 'combinaciones.opciones']);
        $producto->modificadores = $producto->modificadores_estructurados;
        $reservasOpciones = ReservaStockModificador::activas()
            ->whereIn('modificador_opcion_id', collect($producto->modificadores)->flatMap(fn ($grupo) => collect($grupo['opciones'] ?? [])->pluck('id')))
            ->selectRaw('modificador_opcion_id, SUM(cantidad) as cantidad')
            ->groupBy('modificador_opcion_id')->pluck('cantidad', 'modificador_opcion_id');
        $producto->modificadores = collect($producto->modificadores)->map(function ($grupo) use ($reservasOpciones) {
            $grupo['opciones'] = collect($grupo['opciones'] ?? [])->map(function ($opcion) use ($reservasOpciones) {
                if ($opcion['maneja_stock'] && $opcion['stock'] !== null) {
                    $opcion['stock_disponible'] = max(0, (int) $opcion['stock'] - (int) ($reservasOpciones[$opcion['id']] ?? 0));
                }
                return $opcion;
            })->values();
            return $grupo;
        })->values();

        return response()->json([
            'producto' => $producto->load(['categoria', 'estacion'])
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Producto $producto)
    {
        $data = $this->validarProducto($request);
        $categoria = Categoria::with('children')
            ->findOrFail($data['categoria_id']);

        if ($categoria->children->count() > 0) {
            return response()->json([
                'message' => 'Debe seleccionar una subcategoría válida.'
            ], 422);
        }

        return DB::transaction(function () use ($data, $request, $producto) {
            $stockAnterior = $producto->maneja_stock && $producto->stock !== null ? (int) $producto->stock : null;
            if ($request->hasFile('imagen')) {
                if ($producto->imagen) {
                    Storage::disk('public')->delete($producto->imagen);
                }
                $producto->imagen = $request->file('imagen')->store('productos', 'public');
            } elseif ($request->boolean('eliminar_imagen')) {
                if ($producto->imagen) {
                    Storage::disk('public')->delete($producto->imagen);
                }
                $producto->imagen = null;
            }

            $producto->update([
                'categoria_id' => $data['categoria_id'],
                'estacion_id'  => $data['estacion_id'],
                'nombre'       => $data['nombre'],
                'descripcion'  => $data['descripcion'] ?? null,
                'precio'       => $data['precio'],
                'activo'       => $data['activo'] ?? true,
                'maneja_stock' => $data['maneja_stock'] ?? false,
                'stock'        => $data['stock'] ?? null,
                'stock_minimo' => $data['stock_minimo'] ?? null,
                'imagen'       => $producto->imagen,
            ]);

            $stockFinal = $producto->maneja_stock && $producto->stock !== null ? (int) $producto->stock : null;
            if ($stockAnterior !== null && $stockFinal !== null && $stockAnterior !== $stockFinal) {
                AjusteStock::create([
                    'producto_id' => $producto->id,
                    'tipo' => 'CORRECCION',
                    'cantidad' => $stockFinal,
                    'stock_anterior' => $stockAnterior,
                    'stock_final' => $stockFinal,
                    'motivo' => 'Corrección desde la edición del producto',
                    'usuario_id' => auth('api')->id(),
                ]);
                StockActualizadoEvent::dispatch($producto->id, $stockFinal);
            }

            // El método sync() limpia de forma atómica las opciones anteriores y asocia las nuevas
            $opcionesSync = [];
            foreach ($data['opciones'] ?? [] as $opcion) $opcionesSync[$opcion['id']] = ['predeterminado' => $opcion['predeterminado'] ?? false];
            $producto->opciones()->sync($opcionesSync);
            $this->sincronizarConfiguraciones($producto, $data['modificadores'] ?? []);
            if ($request->boolean('combinaciones_configuradas') || array_key_exists('combinaciones', $data)) {
                $this->sincronizarCombinaciones($producto, $data['combinaciones'] ?? [], collect($data['opciones'] ?? [])->pluck('id')->all());
            }

            $snapshot = $producto->only(['id', 'nombre', 'precio', 'activo', 'estacion_id', 'categoria_id']);
            DB::afterCommit(function () use ($snapshot) {
                try { event(new \App\Events\ProductoActualizadoEvent($snapshot)); }
                catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('No se pudo notificar el cambio del producto.', ['producto_id' => $snapshot['id'], 'error' => $e->getMessage()]); }
            });
            return response()->json([
                'message' => 'Producto actualizado correctamente',
                'producto' => $producto->load(['categoria', 'estacion', 'combinaciones.opciones'])
            ]);
        });
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Producto $producto)
    {
        if ($producto->imagen) {
            Storage::disk('public')->delete($producto->imagen);
        }
        
        // Laravel elimina automáticamente las filas en 'producto_opciones' por el onDelete('cascade') de tu migración
        $producto->delete();

        return response()->json([
            'message' => 'Producto eliminado correctamente'
        ]);
    }

    public function ajustarStock(Request $request, Producto $producto)
    {
        $data = $request->validate([
            'cantidad' => 'required|integer|min:1',
        ]);

        return DB::transaction(function () use ($producto, $data) {
            $producto = Producto::whereKey($producto->id)->lockForUpdate()->firstOrFail();

            if (!$producto->maneja_stock || $producto->stock === null) {
                return response()->json(['message' => 'El producto no tiene gestión de stock activa.'], 422);
            }

            $anterior = (int) $producto->stock;
            $final = $anterior + (int) $data['cantidad'];
            $producto->update(['stock' => $final]);

            AjusteStock::create([
                'producto_id' => $producto->id,
                'tipo' => 'ENTRADA',
                'cantidad' => (int) $data['cantidad'],
                'stock_anterior' => $anterior,
                'stock_final' => $final,
                'motivo' => 'Reabastecimiento desde POS',
                'usuario_id' => auth('api')->id(),
            ]);
            StockActualizadoEvent::dispatch($producto->id, $final);

            return response()->json([
                'message' => 'Stock actualizado correctamente',
                'producto' => $producto->fresh()
            ]);
        });
    }

    private function validarProducto(Request $request)
    {
        return $request->validate([
            'categoria_id' => 'required|exists:categorias,id',
            'estacion_id' => [
                'nullable',
                Rule::exists('estaciones_trabajo', 'id')->where(fn ($query) => $query->where('activa', true)),
            ],
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'precio' => 'required|numeric|min:0',
            'activo' => 'boolean',
            'maneja_stock' => 'required|boolean',
            // Validación condicional: Si maneja_stock es true, estos campos son obligatorios
            'stock'  => 'required_if:maneja_stock,true|nullable|integer|min:0',
            'stock_minimo' => 'required_if:maneja_stock,true|nullable|integer|min:0',
            'imagen' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'eliminar_imagen' => 'sometimes|boolean',
            'opciones' => 'nullable|array',
            'opciones.*.id' => 'required|exists:modificador_opciones,id',
            'opciones.*.predeterminado' => 'required|boolean',
            'modificadores' => 'nullable|array',
            'modificadores.*.id' => 'required|exists:modificadores,id',
            'modificadores.*.cantidad_requerida' => 'nullable|integer|min:1|max:20',
            'modificadores.*.cantidad_es_maxima' => 'sometimes|boolean',
            'combinaciones' => 'nullable|array|max:20',
            'combinaciones_configuradas' => 'sometimes|boolean',
            'combinaciones.*.id' => 'nullable|integer',
            'combinaciones.*.nombre' => 'required|string|max:80',
            'combinaciones.*.activo' => 'sometimes|boolean',
            'combinaciones.*.predeterminada' => 'sometimes|boolean',
            'combinaciones.*.orden' => 'nullable|integer|min:0|max:1000',
            'combinaciones.*.opcion_ids' => 'required|array|min:1',
            'combinaciones.*.opcion_ids.*' => 'required|integer|exists:modificador_opciones,id',
        ]);
    }

    private function sincronizarConfiguraciones(Producto $producto, array $configuraciones): void
    {
        $producto->configuracionesModificador()->delete();
        foreach ($configuraciones as $configuracion) {
            ProductoModificadorConfiguracion::create([
                'producto_id' => $producto->id,
                'modificador_id' => $configuracion['id'],
                'cantidad_requerida' => $configuracion['cantidad_requerida'] ?? null,
                'cantidad_es_maxima' => Modificador::find($configuracion['id'])?->usaLimiteMaximo() ?? false,
            ]);
        }
    }

    private function sincronizarCombinaciones(Producto $producto, array $combinaciones, array $opcionesPermitidas): void
    {
        $permitidas = collect($opcionesPermitidas)->map(fn ($id) => (int) $id);
        $nombres = collect($combinaciones)->pluck('nombre')->map(fn ($nombre) => mb_strtolower(trim($nombre)));
        if ($nombres->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['combinaciones' => 'Los nombres de las combinaciones no pueden repetirse.']);
        }
        if (collect($combinaciones)->where('predeterminada', true)->count() > 1) {
            throw ValidationException::withMessages(['combinaciones' => 'Solo una combinación puede ser la predeterminada.']);
        }

        $idsConservados = [];
        $producto->combinaciones()->get()->each(function (ProductoCombinacion $combinacion) {
            $combinacion->update(['nombre' => '__temporal_' . $combinacion->id]);
        });
        $producto->combinaciones()->get()->each(function (ProductoCombinacion $combinacion) {
            $combinacion->update(['nombre' => '__temporal_' . $combinacion->id]);
        });
        foreach ($combinaciones as $indice => $datos) {
            $opcionIdsRecibidos = collect($datos['opcion_ids'])->map(fn ($id) => (int) $id);
            if ($opcionIdsRecibidos->unique()->count() !== $opcionIdsRecibidos->count()) {
                throw ValidationException::withMessages([
                    "combinaciones.$indice.opcion_ids" => 'Una opción no puede repetirse dentro de la misma combinación.',
                ]);
            }
            $opcionIds = $opcionIdsRecibidos->unique()->values();
            if ($opcionIds->diff($permitidas)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    "combinaciones.$indice.opcion_ids" => 'La combinación contiene una opción que no está asignada al producto.',
                ]);
            }
            $this->validarOpcionesCombinacion($producto, $opcionIds, $indice);

            $combinacion = null;
            if (!empty($datos['id'])) {
                $combinacion = $producto->combinaciones()->whereKey($datos['id'])->first();
                if (!$combinacion) {
                    throw ValidationException::withMessages([
                        "combinaciones.$indice.id" => 'La combinación indicada no pertenece al producto.',
                    ]);
                }
            }
            $combinacion ??= new ProductoCombinacion(['producto_id' => $producto->id]);
            $combinacion->fill([
                'nombre' => trim($datos['nombre']),
                'activo' => (bool) ($datos['activo'] ?? true),
                'predeterminada' => (bool) ($datos['predeterminada'] ?? false),
                'orden' => (int) ($datos['orden'] ?? $indice),
            ]);
            $producto->combinaciones()->save($combinacion);
            $combinacion->opciones()->sync($opcionIds->all());
            $idsConservados[] = $combinacion->id;
        }

        $producto->combinaciones()->whereNotIn('id', $idsConservados ?: [0])->delete();
    }

    private function validarOpcionesCombinacion(Producto $producto, $opcionIds, int $indice): void
    {
        $producto->unsetRelation('opciones');
        $producto->unsetRelation('configuracionesModificador');
        $producto->load(['opciones.modificador', 'configuracionesModificador']);
        $opcionesCombinacion = $producto->opciones->whereIn('id', $opcionIds);
        if ($opcionesCombinacion->contains(fn ($opcion) => mb_strtolower(trim($opcion->modificador?->nombre ?? '')) !== 'guarniciones')) {
            throw ValidationException::withMessages([
                "combinaciones.$indice.opcion_ids" => 'Las combinaciones rápidas solo pueden contener guarniciones.',
            ]);
        }
        foreach ($producto->opciones->groupBy('modificador_id') as $opciones) {
            $modificador = $opciones->first()?->modificador;
            if (!$modificador || !$modificador->activo) continue;
            if (mb_strtolower(trim($modificador->nombre)) !== 'guarniciones') continue;
            $idsGrupo = $opciones->where('activo', true)->pluck('id');
            $cantidad = $opcionIds->filter(fn ($id) => $idsGrupo->contains($id))->count();
            $configuracion = $producto->configuracionesModificador->firstWhere('modificador_id', $modificador->id);
            $requerida = $configuracion?->cantidad_requerida;
            $esMaxima = $modificador->usaLimiteMaximo();
            $invalida = ($requerida !== null && $esMaxima && $cantidad > (int) $requerida)
                || ($requerida !== null && !$esMaxima && $cantidad !== (int) $requerida)
                || ($requerida === null && $modificador->requerido && $cantidad === 0)
                || ($requerida === null && $modificador->tipo === 'unico' && $cantidad > 1);
            if ($invalida) {
                throw ValidationException::withMessages([
                    "combinaciones.$indice.opcion_ids" => 'La combinación no cumple la cantidad configurada para “' . $modificador->nombre . '”.',
                ]);
            }
        }
    }

}
