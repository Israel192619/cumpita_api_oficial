<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('mesero_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('tomada_en')->nullable();
            $table->timestamp('entregada_en')->nullable();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('mesa_id')->nullable()->constrained('mesas')->cascadeOnDelete();
            $table->dateTime('fecha_orden')->nullable();
            $table->dateTime('fecha_programada')->nullable();
            $table->unsignedInteger('numero_orden');
            $table->enum('tipo_orden', ['dine-in', 'to-go', 'delivery'])->default('dine-in');
            $table->enum('tipo_flujo', ['normal', 'preorden'])->default('normal');
            $table->enum('estado_preorden', ['programada', 'activada', 'cancelada'])->nullable();
            $table->timestamp('preorden_activada_en')->nullable();
            $table->foreignId('preorden_activada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('preorden_cancelada_en')->nullable();
            $table->foreignId('preorden_cancelada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_cancelacion_preorden')->nullable();
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('descuento', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->enum('estado', ['pendiente', 'preparando', 'listo', 'entregado', 'cancelado'])->default('pendiente');
            $table->enum('estado_pago', ['pendiente', 'parcial', 'completado'])->default('pendiente');
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['mesero_id', 'estado']);
            $table->index(
                ['tipo_flujo', 'estado_preorden', 'fecha_programada'],
                'ordenes_preorden_programacion_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes');
    }
};
