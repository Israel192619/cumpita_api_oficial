<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos_ordenes', function (Blueprint $table) {
            $table->foreignId('pago_origen_id')->nullable()->after('fecha_pago')->constrained('pagos_ordenes')->nullOnDelete();
            $table->foreignId('caja_origen_id')->nullable()->after('pago_origen_id')->constrained('cajas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pagos_ordenes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('caja_origen_id');
            $table->dropConstrainedForeignId('pago_origen_id');
        });
    }
};
