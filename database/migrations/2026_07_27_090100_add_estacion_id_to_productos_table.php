<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            // Nullable solo para conservar productos previos a esta funcionalidad.
            $table->foreignId('estacion_id')->nullable()->after('categoria_id')
                ->constrained('estaciones_trabajo')->nullOnDelete();
            $table->index('estacion_id');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropForeign(['estacion_id']);
            $table->dropIndex(['estacion_id']);
            $table->dropColumn('estacion_id');
        });
    }
};
