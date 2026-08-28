<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orden_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('estacion_id')->nullable()->constrained('estaciones_trabajo')->nullOnDelete();
            $table->integer('cantidad')->default(1);
            $table->decimal('precio_unitario', 10, 2)->default(0);
            $table->string('nota')->nullable();
            $table->enum(
                'estado_cocina',
                ['pendiente', 'en_preparacion', 'listo_para_recoger', 'recogido', 'servido']
            )->default('pendiente');
            $table->timestamp('fecha_servido')->nullable();
            $table->timestamps();

            $table->index('estacion_id');
            $table->index('estado_cocina');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orden_detalles');
    }
};
