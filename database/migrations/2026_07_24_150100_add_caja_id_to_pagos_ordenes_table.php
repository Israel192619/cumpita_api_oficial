<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relaciona pagos en efectivo con la jornada de caja que los recibió.
     */
    public function up(): void
    {
        Schema::table('pagos_ordenes', function (Blueprint $table) {
            $table->foreignId('caja_id')->nullable()->after('id_orden')->constrained('cajas')->nullOnDelete();
            $table->index('caja_id');
        });
    }

    public function down(): void
    {
        Schema::table('pagos_ordenes', function (Blueprint $table) {
            $table->dropIndex(['caja_id']);
            $table->dropForeign(['caja_id']);
            $table->dropColumn('caja_id');
        });
    }
};
