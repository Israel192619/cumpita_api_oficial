<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos');
            $table->enum('tipo', ['ENTRADA', 'SALIDA', 'CORRECCION']);
            $table->integer('cantidad');
            $table->integer('stock_anterior');
            $table->integer('stock_final');
            $table->string('motivo', 255);
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamps();

            $table->index(['producto_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_stock');
    }
};
