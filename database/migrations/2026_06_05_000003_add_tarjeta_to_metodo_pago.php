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
        // Actualizar metodo_pago enum para incluir tarjeta
        Schema::table('ordenes', function (Blueprint $table) {
            // Laravel no permite cambiar enum directamente, así que usamos raw SQL
            $table->dropColumn('metodo_pago');
        });

        Schema::table('ordenes', function (Blueprint $table) {
            $table->enum('metodo_pago', ['efectivo', 'qr', 'tarjeta'])
                ->nullable()
                ->after('estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropColumn('metodo_pago');
        });

        Schema::table('ordenes', function (Blueprint $table) {
            $table->enum('metodo_pago', ['efectivo', 'qr'])
                ->nullable()
                ->after('estado');
        });
    }
};
