<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kds_sesiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('estacion_id')->constrained('estaciones_trabajo')->cascadeOnDelete();
            $table->string('color', 20);
            $table->timestamp('ultima_actividad');
            $table->timestamps();
            $table->unique(['user_id', 'estacion_id']);
        });

        Schema::create('kds_asignaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();
            $table->foreignId('estacion_id')->constrained('estaciones_trabajo')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('asignada_en');
            $table->timestamps();
            $table->unique(['orden_id', 'estacion_id']);
            $table->unique(['user_id', 'estacion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kds_asignaciones');
        Schema::dropIfExists('kds_sesiones');
    }
};
