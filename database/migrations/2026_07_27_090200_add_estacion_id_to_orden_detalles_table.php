<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            // Es el snapshot de la estación al crear o sustituir el producto del detalle.
            $table->foreignId('estacion_id')->nullable()->after('producto_id')
                ->constrained('estaciones_trabajo')->nullOnDelete();
            $table->index('estacion_id');
        });
    }

    public function down(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            $table->dropForeign(['estacion_id']);
            $table->dropIndex(['estacion_id']);
            $table->dropColumn('estacion_id');
        });
    }
};
