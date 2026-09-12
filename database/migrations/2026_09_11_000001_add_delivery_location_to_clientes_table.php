<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('direccion')->nullable()->after('telefono');
            $table->string('referencia_ubicacion')->nullable()->after('direccion');
            $table->decimal('latitud', 10, 7)->nullable()->after('referencia_ubicacion');
            $table->decimal('longitud', 10, 7)->nullable()->after('latitud');
            $table->string('foto_local')->nullable()->after('longitud');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', fn (Blueprint $table) => $table->dropColumn([
            'direccion', 'referencia_ubicacion', 'latitud', 'longitud', 'foto_local',
        ]));
    }
};
