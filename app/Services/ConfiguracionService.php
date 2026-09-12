<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConfiguracionService
{
    public function ubicacionRestaurante(): ?array
    {
        $ubicacion = DB::table('ubicacion_restaurante')->where('id', 1)->first();

        return $ubicacion ? ['latitud' => (float) $ubicacion->latitud, 'longitud' => (float) $ubicacion->longitud] : null;
    }

    public function permiteFechaTrabajo(): bool
    {
        return (bool) (DB::table('configuraciones')->where('clave', 'pos.editar_fecha_trabajo')->value('valor') ?? true);
    }

    public function aplicarFechaTrabajo(Request $request, bool $creando): void
    {
        if ($this->permiteFechaTrabajo()) {
            return;
        }

        if ($creando) {
            $request->merge(['fecha_orden' => now()->format('Y-m-d\TH:i:s')]);
        } else {
            // No alterar la fecha histórica al guardar otros cambios de una orden.
            $request->replace(collect($request->all())->except('fecha_orden')->all());
        }
    }
}
