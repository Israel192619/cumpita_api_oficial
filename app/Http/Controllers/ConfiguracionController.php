<?php

namespace App\Http\Controllers;

use App\Services\ConfiguracionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConfiguracionController extends Controller
{
    public function show(ConfiguracionService $configuracion)
    {
        return response()->json(['pos' => ['editar_fecha_trabajo' => $configuracion->permiteFechaTrabajo()]]);
    }

    public function update(Request $request, ConfiguracionService $configuracion)
    {
        $data = $request->validate(['pos.editar_fecha_trabajo' => 'required|boolean']);
        DB::table('configuraciones')->updateOrInsert(
            ['clave' => 'pos.editar_fecha_trabajo'],
            ['valor' => $data['pos']['editar_fecha_trabajo']]
        );
        try {
            \App\Events\ConfiguracionActualizada::dispatch();
        } catch (\Throwable $exception) {
            // El ajuste ya está guardado; la consulta periódica recuperará el estado.
            report($exception);
        }
        return $this->show($configuracion);
    }
}
