<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos_ordenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_orden')->constrained('ordenes')->cascadeOnDelete();
            $table->foreignId('caja_id')->nullable()->constrained('cajas')->nullOnDelete();
            $table->decimal('monto_recibido', 10, 2);
            $table->decimal('monto_pagado', 10, 2);
            $table->decimal('cambio_devuelto', 10, 2)->default(0.00);
            $table->string('metodo_pago');
            $table->enum('tipo_pago', ['pago', 'devolucion'])->default('pago');
            $table->timestamp('fecha_pago')->useCurrent();
            $table->timestamps();

            $table->index('caja_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_ordenes');
    }
};
