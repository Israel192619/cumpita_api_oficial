<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservas_stock_modificadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modificador_opcion_id')->constrained('modificador_opciones')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('sesion_id');
            $table->unsignedInteger('cantidad');
            $table->timestamp('expira_en');
            $table->timestamps();
            $table->unique(['modificador_opcion_id', 'sesion_id'], 'reserva_modificador_sesion_unique');
            $table->index(['modificador_opcion_id', 'expira_en'], 'reserva_modificador_expira_index');
            $table->index(['sesion_id', 'expira_en'], 'reserva_mod_sesion_expira_index');
        });
    }

    public function down(): void { Schema::dropIfExists('reservas_stock_modificadores'); }
};
