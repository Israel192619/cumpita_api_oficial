<?php

namespace App\Http\Controllers;

use App\Models\Modificador;
use App\Models\ModificadorOpcion;
use App\Models\AjusteStock;
use App\Models\Producto;
use App\Events\ProductoActualizadoEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ModificadorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $modificadores = Modificador::withCount('opciones')
            ->with(['opciones', 'estacion:id,nombre,codigo,activa'])
            ->latest()
            ->get();
        return response()->json([
            'modificadores' => $modificadores,
            'message' => 'Modificadores obtenidos exitosamente.'
            ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'nombre' => 'required|string|max:255',
            'color_fondo' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'tipo' => 'required|in:unico,multiple',
            'requerido' => 'sometimes|boolean',
            'activo' => 'required|boolean',
            'estacion_id' => 'nullable|integer|exists:estaciones_trabajo,id',

            // Validación estricta del array de opciones
            'opciones' => 'required|array|min:1',
            'opciones.*.nombre' => 'required|string|max:255',
            'opciones.*.precio_extra' => 'required|numeric|min:0',
            'opciones.*.imagen' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'opciones.*.mostrar_imagen' => 'sometimes|boolean',
            'opciones.*.activo' => 'boolean',
            'opciones.*.maneja_stock' => 'sometimes|boolean',
            'opciones.*.stock' => 'nullable|integer|min:0',
            'opciones.*.stock_minimo' => 'nullable|integer|min:0',
        ]);
        $validatedData['opciones'] = $this->normalizarStockOpciones($validatedData['opciones']);

        $imagenesNuevas = [];
        try {
            $modificador = DB::transaction(function () use ($validatedData, &$imagenesNuevas) {
                // 1. Crear Modificador Padre
                $nuevoModificador = Modificador::create([
                    'nombre' => $validatedData['nombre'],
                    'color_fondo' => $validatedData['color_fondo'] ?? null,
                    'tipo' => $validatedData['tipo'],
                    'requerido' => $validatedData['requerido'] ?? false,
                    'activo' => $validatedData['activo'] ?? true,
                    'estacion_id' => $validatedData['estacion_id'] ?? null,
                ]);

                foreach ($validatedData['opciones'] as $opcionData) {
                    $imagen = $opcionData['imagen'] ?? null;
                    unset($opcionData['imagen']);
                    if ($imagen) {
                        $opcionData['imagen'] = $imagen->store('modificadores/opciones', 'public');
                        $imagenesNuevas[] = $opcionData['imagen'];
                    }
                    $nuevoModificador->opciones()->create($opcionData);
                }

                return $nuevoModificador->load(['opciones', 'estacion:id,nombre,codigo,activa']);
            });

            return response()->json([
                'success' => true,
                'message' => 'Modificador creado exitosamente junto a sus opciones.',
                'data' => $modificador
            ], 201);

        } catch (\Exception $e) {
            if ($imagenesNuevas) Storage::disk('public')->delete($imagenesNuevas);
            return response()->json([
                'success' => false,
                'error' => 'No se pudo crear el modificador.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Modificador $modificadore)
    {
        return response()->json([
            'modificador' => $modificadore->load(['opciones', 'estacion:id,nombre,codigo,activa']),
            'message' => 'Modificador obtenido exitosamente.'
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Modificador $modificadore)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'color_fondo' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'tipo' => 'required|in:unico,multiple',
            'requerido' => 'sometimes|boolean',
            'activo' => 'required|boolean',
            'estacion_id' => 'nullable|integer|exists:estaciones_trabajo,id',
            
            // El 'id' de la opción es opcional; si viene, debe existir en la BD
            'opciones' => 'required|array|min:1',
            'opciones.*.id' => 'nullable|integer|exists:modificador_opciones,id', 
            'opciones.*.nombre' => 'required|string|max:255',
            'opciones.*.precio_extra' => 'required|numeric|min:0',
            'opciones.*.imagen' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'opciones.*.eliminar_imagen' => 'sometimes|boolean',
            'opciones.*.mostrar_imagen' => 'sometimes|boolean',
            'opciones.*.activo' => 'boolean',
            'opciones.*.maneja_stock' => 'sometimes|boolean',
            'opciones.*.stock' => 'nullable|integer|min:0',
            'opciones.*.stock_minimo' => 'nullable|integer|min:0',
        ]);
        $data['opciones'] = $this->normalizarStockOpciones($data['opciones']);

        $imagenesNuevas = [];
        $imagenesAnteriores = [];
        try {
            $productosAfectados = Producto::whereHas('opciones', fn ($query) =>
                $query->where('modificador_opciones.modificador_id', $modificadore->id)
            )->get(['id', 'nombre', 'precio', 'activo', 'estacion_id', 'categoria_id']);
            DB::transaction(function () use ($data, $modificadore, &$imagenesNuevas, &$imagenesAnteriores) {
                // 1. Actualizar el padre
                $modificadore->update([
                    'nombre' => $data['nombre'],
                    'color_fondo' => $data['color_fondo'] ?? null,
                    'tipo' => $data['tipo'],
                    'requerido' => $data['requerido'] ?? false,
                    'activo' => $data['activo'],
                    'estacion_id' => $data['estacion_id'] ?? null,
                ]);

                $opcionesIdsEnviadas = [];

                // 2. Procesar el array de opciones
                foreach ($data['opciones'] as $opcionData) {
                    $imagen = $opcionData['imagen'] ?? null;
                    $eliminarImagen = (bool) ($opcionData['eliminar_imagen'] ?? false);
                    unset($opcionData['imagen'], $opcionData['eliminar_imagen']);
                    if (!empty($opcionData['id'])) {
                        // SI TIENE ID: Actualiza la opción existente
                        $opcionExistente = $modificadore->opciones()->findOrFail($opcionData['id']);
                        if ($imagen) {
                            $opcionData['imagen'] = $imagen->store('modificadores/opciones', 'public');
                            $imagenesNuevas[] = $opcionData['imagen'];
                            if ($opcionExistente->imagen) $imagenesAnteriores[] = $opcionExistente->imagen;
                        } elseif ($eliminarImagen) {
                            $opcionData['imagen'] = null;
                            $opcionData['mostrar_imagen'] = false;
                            if ($opcionExistente->imagen) $imagenesAnteriores[] = $opcionExistente->imagen;
                        }
                        $stockAnterior = $opcionExistente->maneja_stock && $opcionExistente->stock !== null ? (int) $opcionExistente->stock : null;
                        $opcionExistente->update($opcionData);
                        $stockFinal = $opcionExistente->maneja_stock && $opcionExistente->stock !== null ? (int) $opcionExistente->stock : null;
                        if ($stockAnterior !== null && $stockFinal !== null && $stockAnterior !== $stockFinal) AjusteStock::create([
                            'modificador_opcion_id' => $opcionExistente->id, 'tipo' => 'CORRECCION', 'cantidad' => $stockFinal,
                            'stock_anterior' => $stockAnterior, 'stock_final' => $stockFinal,
                            'motivo' => 'Corrección desde la edición del modificador', 'usuario_id' => auth('api')->id(),
                        ]);
                        $opcionesIdsEnviadas[] = $opcionExistente->id;
                    } else {
                        // SI NO TIENE ID: Es una opción nueva añadida en Angular
                        if ($imagen) {
                            $opcionData['imagen'] = $imagen->store('modificadores/opciones', 'public');
                            $imagenesNuevas[] = $opcionData['imagen'];
                        }
                        $nuevaOpcion = $modificadore->opciones()->create($opcionData);
                        $opcionesIdsEnviadas[] = $nuevaOpcion->id;
                    }
                }

                // 3. LIMPIEZA AUTOMÁTICA: Borra de la BD las opciones que el usuario eliminó en Angular
                $opcionesEliminadas = $modificadore->opciones()->whereNotIn('id', $opcionesIdsEnviadas)->get();
                foreach ($opcionesEliminadas as $opcionEliminada) {
                    if ($opcionEliminada->imagen) $imagenesAnteriores[] = $opcionEliminada->imagen;
                    $opcionEliminada->delete();
                }
            });

            if ($imagenesAnteriores) Storage::disk('public')->delete(array_values(array_unique($imagenesAnteriores)));

            foreach ($productosAfectados as $producto) {
                try {
                    $producto->load(['opciones.modificador', 'configuracionesModificador']);
                    $snapshot = $producto->only(['id', 'nombre', 'precio', 'activo', 'estacion_id', 'categoria_id']);
                    $snapshot['modificadores'] = $producto->modificadores_estructurados->toArray();
                    event(new ProductoActualizadoEvent($snapshot));
                }
                catch (\Throwable $error) { \Illuminate\Support\Facades\Log::warning('No se pudo notificar el cambio de modificador.', ['producto_id' => $producto->id, 'error' => $error->getMessage()]); }
            }
            return response()->json([
                'success' => true,
                'message' => 'Modificador y opciones actualizados correctamente.',
                'data' => $modificadore->load(['opciones', 'estacion:id,nombre,codigo,activa'])
            ], 200);

        } catch (\Exception $e) {
            if ($imagenesNuevas) Storage::disk('public')->delete($imagenesNuevas);
            return response()->json([
                'success' => false,
                'error' => 'No se pudo actualizar el modificador.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Modificador $modificadore)
    {
        try {
            $imagenes = $modificadore->opciones()->whereNotNull('imagen')->pluck('imagen')->all();
            $modificadore->delete();
            if ($imagenes) Storage::disk('public')->delete($imagenes);
            
            return response()->json([
                'success' => true,
                'message' => 'Modificador y todas sus opciones asociadas han sido eliminados.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'No se pudo eliminar el modificador.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    private function normalizarStockOpciones(array $opciones): array
    {
        foreach ($opciones as &$opcion) {
            $opcion['maneja_stock'] = (bool) ($opcion['maneja_stock'] ?? false);
            if ($opcion['maneja_stock'] && !isset($opcion['stock'])) {
                throw ValidationException::withMessages(['opciones' => 'Las opciones con control de stock necesitan un stock inicial.']);
            }
            if (!$opcion['maneja_stock']) $opcion['stock'] = $opcion['stock_minimo'] = null;
            $opcion['mostrar_imagen'] = (bool) ($opcion['mostrar_imagen'] ?? false);
        }
        return $opciones;
    }
}
