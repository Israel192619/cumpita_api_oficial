<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gastos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_id')->constrained('cajas');
            $table->foreignId('usuario_id')->constrained('users');
            $table->enum('categoria', [
                'INSUMOS', 'LIMPIEZA', 'GAS', 'CARBON', 'TRANSPORTE',
                'MANTENIMIENTO', 'SERVICIOS', 'PERSONAL', 'OTROS',
            ]);
            $table->string('concepto', 255);
            $table->decimal('monto', 10, 2);
            $table->enum('estado', ['ACTIVO', 'ANULADO'])->default('ACTIVO');
            $table->foreignId('anulado_por')->nullable()->constrained('users');
            $table->timestamp('anulado_en')->nullable();
            $table->string('motivo_anulacion', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gastos_caja');
    }
};
