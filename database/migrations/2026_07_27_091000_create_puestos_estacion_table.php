<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puestos_estacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estacion_id')->constrained('estaciones_trabajo')->cascadeOnDelete();
            $table->string('nombre', 50);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['estacion_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puestos_estacion');
    }
};
