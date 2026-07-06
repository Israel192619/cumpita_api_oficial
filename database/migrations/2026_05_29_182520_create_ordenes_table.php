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
        Schema::create('ordenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('cliente_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('mesa_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('fecha_orden');
            $table->enum('tipo_orden', ['dine-in', 'to-go', 'delivery'])->default('dine-in');
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('descuento', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->enum('estado', [
                'pendiente',   // creada
                'preparando',  // cocina
                'listo',       // listo para servir
                'entregado',   // entregado al cliente
                'pagado',      // cobrado
                'cancelado'
            ])->default('pendiente');
            $table->enum('metodo_pago', [
                'efectivo',
                'qr',
            ])->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ordenes');
    }
};
