<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orden_detalle_estaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_detalle_id')->constrained('orden_detalles')->cascadeOnDelete();
            $table->foreignId('estacion_id')->constrained('estaciones_trabajo')->cascadeOnDelete();
            $table->enum('estado', ['pendiente', 'en_preparacion', 'listo_para_recoger', 'recogido', 'servido'])->default('pendiente');
            $table->timestamp('fecha_servido')->nullable();
            $table->timestamps();
            $table->unique(['orden_detalle_id', 'estacion_id']);
            $table->index(['estacion_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orden_detalle_estaciones');
    }
};
