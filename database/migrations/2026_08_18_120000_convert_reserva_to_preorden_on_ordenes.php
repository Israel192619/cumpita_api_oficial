<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->renameColumn('fecha_reserva', 'fecha_programada');
        });

        Schema::table('ordenes', function (Blueprint $table) {
            $table->enum('tipo_flujo', ['normal', 'preorden'])->default('normal')->after('tipo_orden');
            $table->enum('estado_preorden', ['programada', 'activada', 'cancelada'])->nullable()->after('tipo_flujo');
            $table->timestamp('preorden_activada_en')->nullable()->after('estado_preorden');
            $table->foreignId('preorden_activada_por')->nullable()->after('preorden_activada_en')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('preorden_cancelada_en')->nullable()->after('preorden_activada_por');
            $table->foreignId('preorden_cancelada_por')->nullable()->after('preorden_cancelada_en')
                ->constrained('users')->nullOnDelete();
            $table->string('motivo_cancelacion_preorden')->nullable()->after('preorden_cancelada_por');
            $table->index(['tipo_flujo', 'estado_preorden', 'fecha_programada'], 'ordenes_preorden_programacion_idx');
        });

        // Las reservas históricas ya estuvieron expuestas al flujo operativo. Se marcan
        // como activadas para no ocultarlas ni reactivarlas accidentalmente.
        DB::table('ordenes')->whereNotNull('fecha_programada')->update([
            'tipo_flujo' => 'preorden',
            'estado_preorden' => 'activada',
            'preorden_activada_en' => DB::raw('COALESCE(fecha_orden, created_at)'),
        ]);

        DB::table('ordenes')
            ->whereNotNull('fecha_programada')
            ->where('estado', 'cancelado')
            ->update([
                'estado_preorden' => 'cancelada',
                'preorden_cancelada_en' => DB::raw('COALESCE(updated_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropIndex('ordenes_preorden_programacion_idx');
            $table->dropForeign(['preorden_activada_por']);
            $table->dropForeign(['preorden_cancelada_por']);
            $table->dropColumn([
                'tipo_flujo', 'estado_preorden', 'preorden_activada_en', 'preorden_activada_por',
                'preorden_cancelada_en', 'preorden_cancelada_por', 'motivo_cancelacion_preorden',
            ]);
        });

        Schema::table('ordenes', function (Blueprint $table) {
            $table->renameColumn('fecha_programada', 'fecha_reserva');
        });
    }
};
