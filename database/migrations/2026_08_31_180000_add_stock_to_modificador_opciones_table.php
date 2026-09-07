<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('modificador_opciones', function (Blueprint $table) {
            $table->boolean('maneja_stock')->default(false)->after('activo');
            $table->integer('stock')->nullable()->after('maneja_stock');
            $table->integer('stock_minimo')->nullable()->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('modificador_opciones', function (Blueprint $table) {
            $table->dropColumn(['maneja_stock', 'stock', 'stock_minimo']);
        });
    }
};
