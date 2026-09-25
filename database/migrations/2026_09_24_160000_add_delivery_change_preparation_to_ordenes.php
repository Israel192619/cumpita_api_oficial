<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->decimal('delivery_monto_esperado', 10, 2)->nullable()->after('estado_pago');
            $table->boolean('delivery_cambio_preparado')->default(false)->after('delivery_monto_esperado');
            $table->foreignId('delivery_cambio_preparado_por')->nullable()->after('delivery_cambio_preparado')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('delivery_cambio_preparado_en')->nullable()->after('delivery_cambio_preparado_por');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropForeign(['delivery_cambio_preparado_por']);
            $table->dropColumn([
                'delivery_monto_esperado',
                'delivery_cambio_preparado',
                'delivery_cambio_preparado_por',
                'delivery_cambio_preparado_en',
            ]);
        });
    }
};
