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
        Schema::create('orden_detalle_opciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_detalle_id')
                ->constrained('orden_detalles')
                ->cascadeOnDelete();

            $table->foreignId('modificador_opcion_id')
                ->constrained('modificador_opciones')
                ->cascadeOnDelete();

            $table->decimal('precio_extra', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orden_detalle_opciones');
    }
};
