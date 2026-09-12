<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('telefono_normalizado', 30)->nullable()->index()->after('telefono');
        });

        Schema::table('ordenes', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->enum('origen_registro', ['interno', 'cliente'])->default('interno')->after('tipo_flujo');
            $table->enum('estado_solicitud', ['pendiente', 'aceptada', 'rechazada', 'vencida', 'cancelada'])->nullable()->after('origen_registro');
            $table->uuid('codigo_publico')->nullable()->unique()->after('estado_solicitud');
            $table->foreignId('solicitud_revisada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('solicitud_revisada_en')->nullable();
            $table->string('motivo_rechazo')->nullable();
            $table->index(['origen_registro', 'estado_solicitud'], 'ordenes_solicitud_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropIndex('ordenes_solicitud_estado_idx');
            $table->dropForeign(['solicitud_revisada_por']);
            $table->dropUnique(['codigo_publico']);
            $table->dropColumn(['origen_registro', 'estado_solicitud', 'codigo_publico', 'solicitud_revisada_por', 'solicitud_revisada_en', 'motivo_rechazo']);
            $table->foreignId('user_id')->nullable(false)->change();
        });
        Schema::table('clientes', fn (Blueprint $table) => $table->dropColumn('telefono_normalizado'));
    }
};
