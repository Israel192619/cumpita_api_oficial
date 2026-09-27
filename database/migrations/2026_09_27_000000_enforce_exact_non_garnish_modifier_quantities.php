<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('producto_modificador_configuraciones')
            ->whereNotIn('modificador_id', DB::table('modificadores')
                ->whereIn(DB::raw('LOWER(TRIM(nombre))'), ['guarnicion', 'guarniciones'])
                ->select('id'))
            ->update(['cantidad_es_maxima' => false]);
    }

    public function down(): void
    {
        // No se reactiva una configuración que permitía guardar platos incompletos.
    }
};
