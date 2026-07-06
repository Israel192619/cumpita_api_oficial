<?php

namespace App\Http\Controllers;

use App\Models\Mesa;
use Illuminate\Http\Request;

class MesaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $mesas = Mesa::all();
        return response()->json([
            'mesas' => $mesas
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'numero' => 'required|string|unique:mesas,numero',
            'capacidad' => 'required|integer|min:1',
            'estado' => 'required|in:libre,ocupada,reservada,mantenimiento',
        ]);

        $mesa = Mesa::create($validatedData);

        return response()->json([
            'mesa' => $mesa,
            'message' => 'Mesa creada exitosamente.'
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $mesa = Mesa::findOrFail($id);
        return response()->json([
            'mesa' => $mesa
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $mesa = Mesa::findOrFail($id);

        $validatedData = $request->validate([
            'numero' => 'required|string|unique:mesas,numero,' . $id,
            'capacidad' => 'required|integer|min:1',
            'estado' => 'required|in:libre,ocupada,reservada,mantenimiento',
        ]);

        $mesa->update($validatedData);

        return response()->json([
            'mesa' => $mesa,
            'message' => 'Mesa actualizada exitosamente.'
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $mesa = Mesa::findOrFail($id);
        $mesa->delete();

        return response()->json([
            'message' => 'Mesa eliminada exitosamente.'
        ], 200);
    }
}
