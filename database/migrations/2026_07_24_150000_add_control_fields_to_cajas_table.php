<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Añade los datos necesarios para controlar una jornada de caja.
     */
    public function up(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->decimal('monto_apertura', 10, 2)->default(0)->after('user_id');
            $table->decimal('monto_esperado', 10, 2)->nullable()->after('monto_apertura');
            $table->decimal('monto_cierre', 10, 2)->nullable()->after('monto_esperado');
            $table->decimal('diferencia', 10, 2)->nullable()->after('monto_cierre');
            $table->timestamp('fecha_apertura')->useCurrent()->after('diferencia');
            $table->timestamp('fecha_cierre')->nullable()->after('fecha_apertura');
            $table->enum('estado', ['abierta', 'cerrada'])->default('abierta')->after('fecha_cierre');
            $table->text('observacion_apertura')->nullable()->after('estado');
            $table->text('observacion_cierre')->nullable()->after('observacion_apertura');
            $table->index(['user_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'estado']);
            $table->dropForeign(['user_id']);
            $table->dropColumn([
                'user_id',
                'monto_apertura',
                'monto_esperado',
                'monto_cierre',
                'diferencia',
                'fecha_apertura',
                'fecha_cierre',
                'estado',
                'observacion_apertura',
                'observacion_cierre',
            ]);
        });
    }
};
