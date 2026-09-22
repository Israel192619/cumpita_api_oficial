<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('producto_modificador_configuraciones')
            ->whereNotNull('cantidad_requerida')
            ->whereIn('modificador_id', DB::table('modificadores')
                ->whereRaw('LOWER(nombre) = ?', ['guarniciones'])->select('id'))
            ->update(['cantidad_es_maxima' => true]);
    }

    public function down(): void
    {
        // La intención previa de cada producto no se puede reconstruir con certeza.
    }
};
