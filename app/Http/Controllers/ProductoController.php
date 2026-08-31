<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\AjusteStock;
use App\Events\StockActualizadoEvent;
use App\Models\ReservaStock;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProductoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // OPTIMIZACIÓN: Añadimos 'opciones.modificador' al método 'with'
        $query = Producto::with(['categoria', 'estacion', 'opciones.modificador']);

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
        $productos->each(function ($producto) use ($reservas) {
            $producto->stock_disponible = $producto->maneja_stock && $producto->stock !== null
                ? max(0, (int) $producto->stock - (int) ($reservas[$producto->id] ?? 0))
                : null;
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
            return response()->json([
                'producto' => $producto->load(['categoria', 'estacion']),
                'message'  => 'Producto creado correctamente'
            ], 201);
        });
    }

    /**
     * Display the specified resource.
     */
    public function show(Producto $producto)
    {
        $producto->modificadores = $producto->modificadores_estructurados;

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
            if (!empty($data['opciones'])) {

                $opcionesSync = [];

                foreach ($data['opciones'] as $opcion) {
                    $opcionesSync[$opcion['id']] = [
                        'predeterminado' => $opcion['predeterminado'] ?? false
                    ];
                }

                $producto->opciones()->sync($opcionesSync);
            }

            return response()->json([
                'message' => 'Producto actualizado correctamente',
                'producto' => $producto->load(['categoria', 'estacion'])
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
            'opciones' => 'nullable|array',
            'opciones.*.id' => 'required|exists:modificador_opciones,id',
            'opciones.*.predeterminado' => 'required|boolean',
        ]);
    }

}
