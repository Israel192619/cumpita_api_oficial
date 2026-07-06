<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\Request;

class ClienteController extends Controller
{
    /**
     * Search for clients by name or phone.
     */
    public function search(Request $request)
    {
        $query = $request->query('q', '');
        
        if (strlen($query) < 2) {
            return response()->json([
                'clientes' => []
            ], 200);
        }

        $clientes = Cliente::where('nombre', 'like', "%{$query}%")
            ->orWhere('telefono', 'like', "%{$query}%")
            ->limit(10)
            ->get();

        return response()->json([
            'clientes' => $clientes
        ], 200);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $clientes = Cliente::all();
        return response()->json([
            'clientes' => $clientes
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'nombre' => 'required|string|max:255',
            'telefono' => 'nullable|string|max:20',
        ]);

        $cliente = Cliente::create($validatedData);

        return response()->json([
            'cliente' => $cliente,
            'message' => 'Cliente creado exitosamente.'
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $cliente = Cliente::findOrFail($id);
        return response()->json([
            'cliente' => $cliente
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $cliente = Cliente::findOrFail($id);

        $validatedData = $request->validate([
            'nombre' => 'required|string|max:255',
            'telefono' => 'nullable|string|max:20',
        ]);

        $cliente->update($validatedData);

        return response()->json([
            'cliente' => $cliente,
            'message' => 'Cliente actualizado exitosamente.'
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $cliente = Cliente::findOrFail($id);
        $cliente->delete();

        return response()->json([
            'message' => 'Cliente eliminado exitosamente.'
        ], 200);
    }
}
