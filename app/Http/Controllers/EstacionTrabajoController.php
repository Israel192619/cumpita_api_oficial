<?php

namespace App\Http\Controllers;

use App\Models\EstacionTrabajo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EstacionTrabajoController extends Controller
{
    public function index()
    {
        return response()->json([
            'estaciones' => EstacionTrabajo::orderBy('orden')->orderBy('nombre')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $estacion = EstacionTrabajo::create($this->validar($request));

        return response()->json([
            'message' => 'Estación de trabajo creada correctamente.',
            'estacion' => $estacion,
        ], 201);
    }

    public function show(EstacionTrabajo $estacionTrabajo)
    {
        return response()->json(['estacion' => $estacionTrabajo]);
    }

    public function update(Request $request, EstacionTrabajo $estacionTrabajo)
    {
        $estacionTrabajo->update($this->validar($request, $estacionTrabajo));

        return response()->json([
            'message' => 'Estación de trabajo actualizada correctamente.',
            'estacion' => $estacionTrabajo->fresh(),
        ]);
    }

    public function destroy(EstacionTrabajo $estacionTrabajo)
    {
        if ($estacionTrabajo->productos()->exists() || $estacionTrabajo->detallesOrden()->exists() || $estacionTrabajo->usuarios()->exists()) {
            return response()->json([
                'message' => 'La estación tiene productos, usuarios o detalles históricos asociados. Desactívala en lugar de eliminarla.',
            ], 422);
        }

        $estacionTrabajo->delete();

        return response()->json(['message' => 'Estación de trabajo eliminada correctamente.']);
    }

    private function validar(Request $request, ?EstacionTrabajo $estacion = null): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255', Rule::unique('estaciones_trabajo', 'nombre')->ignore($estacion?->id)],
            'codigo' => ['required', 'string', 'max:50', Rule::unique('estaciones_trabajo', 'codigo')->ignore($estacion?->id)],
            'descripcion' => ['nullable', 'string'],
            'activa' => ['sometimes', 'boolean'],
            'orden' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
