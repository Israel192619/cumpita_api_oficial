<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modificadores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->foreignId('estacion_id')->nullable()->constrained('estaciones_trabajo')->nullOnDelete();
            $table->enum('tipo', ['unico', 'multiple'])->default('unico');
            $table->boolean('requerido')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modificadores');
    }
};
