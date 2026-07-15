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
        Schema::create('pagos_ordenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_orden')->constrained('ordenes', 'id')->onDelete('cascade');
            $table->decimal('monto_recibido', 10, 2); // Con cuánto pagó (ej: 20.00)
            $table->decimal('monto_pagado', 10, 2);   // Lo que realmente se abonó a la cuenta (ej: 15.00)
            $table->decimal('cambio_devuelto', 10, 2)->default(0.00); // El vuelto (ej: 5.00)
            $table->string('metodo_pago'); 
            $table->enum('tipo_pago', ['pago','devolucion'])->default('pago'); // Tipo de pago
            $table->timestamp('fecha_pago')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagos_ordenes');
    }
};
