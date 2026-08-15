<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modificadores', function (Blueprint $table) {
            $table->foreignId('estacion_id')
                ->nullable()
                ->after('nombre')
                ->constrained('estaciones_trabajo')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('modificadores', function (Blueprint $table) {
            $table->dropForeign(['estacion_id']);
            $table->dropColumn('estacion_id');
        });
    }
};
