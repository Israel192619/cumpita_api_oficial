<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ajustes_stock', function (Blueprint $table) {
            $table->foreignId('revertido_por_ajuste_id')->nullable()->after('usuario_id')->constrained('ajustes_stock');
        });
    }

    public function down(): void
    {
        Schema::table('ajustes_stock', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revertido_por_ajuste_id');
        });
    }
};
