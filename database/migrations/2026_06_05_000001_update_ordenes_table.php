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
        Schema::table('ordenes', function (Blueprint $table) {
            // Hacer nullable los foreign keys para soportar to-go y delivery
            $table->foreignId('cliente_id')->nullable()->change();
            $table->foreignId('mesa_id')->nullable()->change();
            
            // Agregar tipo_orden y soporte para tarjeta
            if (!Schema::hasColumn('ordenes', 'tipo_orden')) {
                $table->enum('tipo_orden', ['dine-in', 'to-go', 'delivery'])
                    ->default('dine-in')
                    ->after('metodo_pago');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropColumn(['tipo_orden']);
        });
    }
};
