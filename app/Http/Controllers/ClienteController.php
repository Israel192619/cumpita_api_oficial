<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
            'direccion' => 'nullable|string|max:255',
            'referencia_ubicacion' => 'nullable|string|max:255',
            'latitud' => 'nullable|numeric|between:-90,90',
            'longitud' => 'nullable|numeric|between:-180,180',
            'foto_local' => 'nullable|image|max:5120',
        ]);

        $validatedData['nombre'] = trim($validatedData['nombre']);
        $clienteExistente = Cliente::whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower($validatedData['nombre'])])->first();
        if ($clienteExistente) {
            if (!$clienteExistente->telefono && !empty($validatedData['telefono'])) {
                $clienteExistente->update(['telefono' => $validatedData['telefono']]);
            }
            return response()->json([
                'cliente' => $clienteExistente->fresh(),
                'message' => 'El cliente ya existía y fue seleccionado.',
            ], 200);
        }

        if ($request->hasFile('foto_local')) {
            $validatedData['foto_local'] = $request->file('foto_local')->store('clientes/locales', 'public');
        }

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
            'direccion' => 'nullable|string|max:255',
            'referencia_ubicacion' => 'nullable|string|max:255',
            'latitud' => 'nullable|numeric|between:-90,90',
            'longitud' => 'nullable|numeric|between:-180,180',
            'foto_local' => 'nullable|image|max:5120',
        ]);

        if ($request->hasFile('foto_local')) {
            if ($cliente->foto_local) Storage::disk('public')->delete($cliente->foto_local);
            $validatedData['foto_local'] = $request->file('foto_local')->store('clientes/locales', 'public');
        }

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
        if ($cliente->foto_local) Storage::disk('public')->delete($cliente->foto_local);
        $cliente->delete();

        return response()->json([
            'message' => 'Cliente eliminado exitosamente.'
        ], 200);
    }
}
