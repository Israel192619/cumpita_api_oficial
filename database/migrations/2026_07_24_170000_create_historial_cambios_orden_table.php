<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historial_cambios_orden', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();
            $table->foreignId('orden_detalle_id')->nullable()->constrained('orden_detalles')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->enum('tipo_cambio', [
                'detalle_agregado',
                'detalle_modificado',
                'detalle_eliminado',
                'estado_cambiado',
                'orden_cancelada',
            ]);
            $table->integer('cantidad_anterior')->nullable();
            $table->integer('cantidad_nueva')->nullable();
            $table->json('datos_anterior')->nullable();
            $table->json('datos_nuevo')->nullable();
            $table->timestamps();
            $table->index(['orden_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_cambios_orden');
    }
};
