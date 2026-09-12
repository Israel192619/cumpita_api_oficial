<?php

namespace App\Http\Controllers;

use App\Events\ConfiguracionActualizada;
use App\Services\ConfiguracionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConfiguracionController extends Controller
{
    public function show(ConfiguracionService $configuracion)
    {
        return response()->json([
            'pos' => ['editar_fecha_trabajo' => $configuracion->permiteFechaTrabajo()],
            'restaurante' => $configuracion->ubicacionRestaurante(),
        ]);
    }

    public function update(Request $request, ConfiguracionService $configuracion)
    {
        $data = $request->validate([
            'pos.editar_fecha_trabajo' => 'sometimes|required|boolean',
            'restaurante' => 'sometimes|required|array',
            'restaurante.latitud' => 'required_with:restaurante|numeric|between:-90,90',
            'restaurante.longitud' => 'required_with:restaurante|numeric|between:-180,180',
        ]);
        if (isset($data['pos'])) {
            DB::table('configuraciones')->updateOrInsert(
                ['clave' => 'pos.editar_fecha_trabajo'],
                ['valor' => $data['pos']['editar_fecha_trabajo']]
            );
        }
        if (isset($data['restaurante'])) {
            DB::table('ubicacion_restaurante')->updateOrInsert(
                ['id' => 1],
                ['latitud' => $data['restaurante']['latitud'], 'longitud' => $data['restaurante']['longitud'], 'updated_at' => now(), 'created_at' => now()]
            );
        }
        try {
            ConfiguracionActualizada::dispatch();
        } catch (\Throwable $exception) {
            // El ajuste ya está guardado; la consulta periódica recuperará el estado.
            report($exception);
        }

        return $this->show($configuracion);
    }
}
