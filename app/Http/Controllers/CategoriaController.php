<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CategoriaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $categorias = Categoria::with('children')->whereNull('parent_id')->get();
        return response()->json([
            'categorias' => $categorias
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:255|unique:categorias',
            'descripcion' => 'nullable|string',
            'parent_id' => 'nullable|exists:categorias,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        DB::beginTransaction();
        try{
        $categoria = Categoria::create($request->all());
        DB::commit();
        return response()->json([
            'message' => 'Categoria creada exitosamente',
            'categoria' => $categoria
        ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al crear la categoria', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $categoria = Categoria::with('children')->findOrFail($id);
        if (!$categoria) {
            return response()->json(['message' => 'Categoria no encontrada'], 404);
        }
        return response()->json([
            'message' => 'Categoria encontrada',
            'categoria' => $categoria
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $categoria = Categoria::find($id);
        if (!$categoria) {
            return response()->json(['message' => 'Categoria no encontrada'], 404);
        }

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:255|unique:categorias,nombre,' . $categoria->id,
            'descripcion' => 'nullable|string',
            'parent_id' => 'nullable|exists:categorias,id'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        if ($request->parent_id == $categoria->id) {
            return response()->json(['message' => 'Una categoria no puede ser su propia padre'], 422);
        }
        DB::beginTransaction();
        try {
            $categoria->update($request->all());
            DB::commit();
            return response()->json([
                'message' => 'Categoria actualizada exitosamente',
                'categoria' => $categoria
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al actualizar la categoria', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        DB::beginTransaction();
         try {
             $categoria = Categoria::find($id);
             if (!$categoria) {
                 DB::rollBack();
                 return response()->json(['message' => 'Categoria no encontrada'], 404);
             }
             if ($categoria->children()->count() > 0) {
                return response()->json([
                    'error' => 'No puedes eliminar una categoría con subcategorías'
                ], 422);
            }
             $categoria->delete();
             DB::commit();
             return response()->json(['message' => 'Categoria eliminada exitosamente'], 200);
         } catch (\Exception $e) {
             DB::rollBack();
             return response()->json(['message' => 'Error al eliminar la categoria', 'error' => $e->getMessage()], 500);
         }
    }
}
