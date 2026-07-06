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
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')
                ->constrained('categorias')
                ->cascadeOnDelete();

            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->decimal('precio', 10, 2);
            $table->string('sku')->unique()->nullable();
            $table->string('imagen')->nullable();
            $table->boolean('maneja_stock')->default(false);
            $table->integer('stock')->nullable();
            $table->integer('stock_minimo')->nullable();
            $table->integer('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->boolean('destacado')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
