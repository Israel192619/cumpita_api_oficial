<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modificador_opciones', function (Blueprint $table) {
            $table->string('imagen')->nullable()->after('precio_extra');
            $table->boolean('mostrar_imagen')->default(false)->after('imagen');
        });
    }

    public function down(): void
    {
        Schema::table('modificador_opciones', function (Blueprint $table) {
            $table->dropColumn(['imagen', 'mostrar_imagen']);
        });
    }
};
