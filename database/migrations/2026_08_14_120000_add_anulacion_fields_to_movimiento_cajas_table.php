<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimiento_cajas', function (Blueprint $table) {
            $table->enum('estado', ['ACTIVO', 'ANULADO'])->default('ACTIVO')->after('usuario_id');
            $table->foreignId('anulado_por')->nullable()->after('estado')->constrained('users');
            $table->timestamp('anulado_en')->nullable()->after('anulado_por');
            $table->string('motivo_anulacion', 500)->nullable()->after('anulado_en');
        });
    }

    public function down(): void
    {
        Schema::table('movimiento_cajas', function (Blueprint $table) {
            $table->dropForeign(['anulado_por']);
            $table->dropColumn(['estado', 'anulado_por', 'anulado_en', 'motivo_anulacion']);
        });
    }
};
