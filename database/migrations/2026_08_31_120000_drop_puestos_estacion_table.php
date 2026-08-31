<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('puestos_estacion');
    }

    public function down(): void
    {
        Schema::create('puestos_estacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->constrained('estaciones_trabajo')->cascadeOnDelete();
            $table->string('nombre');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('orden_id')->nullable()->constrained('ordenes')->nullOnDelete();
            $table->timestamps();
            $table->unique(['estacion_id', 'nombre']);
            $table->unique('orden_id');
        });
    }
};
