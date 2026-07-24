<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            $table->enum('estado_cocina', ['pendiente', 'servido'])
                ->default('pendiente')
                ->after('nota');
            $table->timestamp('fecha_servido')->nullable()->after('estado_cocina');
            $table->index('estado_cocina');
        });
    }

    public function down(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            $table->dropIndex(['estado_cocina']);
            $table->dropColumn(['estado_cocina', 'fecha_servido']);
        });
    }
};
