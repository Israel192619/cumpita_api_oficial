<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', fn (Blueprint $table) => $table->timestamp('solicitud_expira_en')->nullable()->index());
        Schema::table('reservas_stock', fn (Blueprint $table) => $table->foreignId('usuario_id')->nullable()->change());
        Schema::table('reservas_stock_modificadores', fn (Blueprint $table) => $table->foreignId('usuario_id')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('ordenes', fn (Blueprint $table) => $table->dropColumn('solicitud_expira_en'));
        Schema::table('reservas_stock', fn (Blueprint $table) => $table->foreignId('usuario_id')->nullable(false)->change());
        Schema::table('reservas_stock_modificadores', fn (Blueprint $table) => $table->foreignId('usuario_id')->nullable(false)->change());
    }
};
