<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            // Agregar campos de cantidad y precio unitario
            if (!Schema::hasColumn('orden_detalles', 'cantidad')) {
                $table->integer('cantidad')->default(1)->after('producto_id');
            }
            if (!Schema::hasColumn('orden_detalles', 'precio_unitario')) {
                $table->decimal('precio_unitario', 10, 2)->default(0)->after('cantidad');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            $table->dropColumn(['cantidad', 'precio_unitario']);
        });
    }
};
