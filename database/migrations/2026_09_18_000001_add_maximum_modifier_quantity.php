<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('producto_modificador_configuraciones', function (Blueprint $table) {
            $table->boolean('cantidad_es_maxima')->default(false);
        });

        // Las guarniciones configuradas con 3 admiten de 0 a 3 elecciones.
        DB::table('producto_modificador_configuraciones')
            ->where('cantidad_requerida', 3)
            ->whereIn('modificador_id', DB::table('modificadores')->whereRaw('LOWER(nombre) = ?', ['guarniciones'])->select('id'))
            ->update(['cantidad_es_maxima' => true]);
    }

    public function down(): void
    {
        Schema::table('producto_modificador_configuraciones', function (Blueprint $table) {
            $table->dropColumn('cantidad_es_maxima');
        });
    }
};
