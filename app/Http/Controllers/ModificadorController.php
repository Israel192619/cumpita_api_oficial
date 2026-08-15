<?php

namespace App\Http\Controllers;

use App\Models\Modificador;
use App\Models\ModificadorOpcion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'tipo' => 'required|in:unico,multiple',
            'requerido' => 'required|boolean',
            'activo' => 'required|boolean',
            'estacion_id' => 'nullable|integer|exists:estaciones_trabajo,id',

            // Validación estricta del array de opciones
            'opciones' => 'required|array|min:1',
            'opciones.*.nombre' => 'required|string|max:255',
            'opciones.*.precio_extra' => 'required|numeric|min:0',
            'opciones.*.activo' => 'boolean',
        ]);

        try {
            $modificador = DB::transaction(function () use ($validatedData) {
                // 1. Crear Modificador Padre
                $nuevoModificador = Modificador::create([
                    'nombre' => $validatedData['nombre'],
                    'tipo' => $validatedData['tipo'],
                    'requerido' => $validatedData['requerido'],
                    'activo' => $validatedData['activo'] ?? true,
                    'estacion_id' => $validatedData['estacion_id'] ?? null,
                ]);

                // 2. Crear las opciones hijas (inyecta automáticamente el modificador_id)
                $nuevoModificador->opciones()->createMany($validatedData['opciones']);

                return $nuevoModificador->load(['opciones', 'estacion:id,nombre,codigo,activa']);
            });

            return response()->json([
                'success' => true,
                'message' => 'Modificador creado exitosamente junto a sus opciones.',
                'data' => $modificador
            ], 201);

        } catch (\Exception $e) {
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
            'tipo' => 'required|in:unico,multiple',
            'requerido' => 'required|boolean',
            'activo' => 'required|boolean',
            'estacion_id' => 'nullable|integer|exists:estaciones_trabajo,id',
            
            // El 'id' de la opción es opcional; si viene, debe existir en la BD
            'opciones' => 'required|array|min:1',
            'opciones.*.id' => 'nullable|integer|exists:modificador_opciones,id', 
            'opciones.*.nombre' => 'required|string|max:255',
            'opciones.*.precio_extra' => 'required|numeric|min:0',
            'opciones.*.activo' => 'boolean',
        ]);

        try {
            DB::transaction(function () use ($data, $modificadore) {
                // 1. Actualizar el padre
                $modificadore->update([
                    'nombre' => $data['nombre'],
                    'tipo' => $data['tipo'],
                    'requerido' => $data['requerido'],
                    'activo' => $data['activo'],
                    'estacion_id' => $data['estacion_id'] ?? null,
                ]);

                $opcionesIdsEnviadas = [];

                // 2. Procesar el array de opciones
                foreach ($data['opciones'] as $opcionData) {
                    if (!empty($opcionData['id'])) {
                        // SI TIENE ID: Actualiza la opción existente
                        $opcionExistente = ModificadorOpcion::findOrFail($opcionData['id']);
                        $opcionExistente->update($opcionData);
                        $opcionesIdsEnviadas[] = $opcionExistente->id;
                    } else {
                        // SI NO TIENE ID: Es una opción nueva añadida en Angular
                        $nuevaOpcion = $modificadore->opciones()->create($opcionData);
                        $opcionesIdsEnviadas[] = $nuevaOpcion->id;
                    }
                }

                // 3. LIMPIEZA AUTOMÁTICA: Borra de la BD las opciones que el usuario eliminó en Angular
                $modificadore->opciones()->whereNotIn('id', $opcionesIdsEnviadas)->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'Modificador y opciones actualizados correctamente.',
                'data' => $modificadore->load(['opciones', 'estacion:id,nombre,codigo,activa'])
            ], 200);

        } catch (\Exception $e) {
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
            $modificadore->delete();
            
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
}
