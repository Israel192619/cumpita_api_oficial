<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class ConfiguracionService
{
    public function permiteFechaTrabajo(): bool
    {
        return (bool) (DB::table('configuraciones')->where('clave', 'pos.editar_fecha_trabajo')->value('valor') ?? true);
    }

    public function aplicarFechaTrabajo(Request $request, bool $creando): void
    {
        if ($this->permiteFechaTrabajo()) return;

        if ($creando) {
            $request->merge(['fecha_orden' => now()->format('Y-m-d\TH:i:s')]);
        } else {
            // No alterar la fecha histórica al guardar otros cambios de una orden.
            $request->replace(collect($request->all())->except('fecha_orden')->all());
        }
    }
}
