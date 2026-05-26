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
        Schema::create('modificador_opciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modificador_id')->constrained()->onDelete('cascade');
            $table->string('nombre');
            $table->decimal('precio_extra', 10, 2)->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('modificador_opciones');
    }
};
