<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservas_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('sesion_id');
            $table->unsignedInteger('cantidad');
            $table->timestamp('expira_en');
            $table->timestamps();
            $table->unique(['producto_id', 'sesion_id']);
            $table->index(['producto_id', 'expira_en']);
            $table->index(['sesion_id', 'expira_en']);
        });
    }
    public function down(): void { Schema::dropIfExists('reservas_stock'); }
};
