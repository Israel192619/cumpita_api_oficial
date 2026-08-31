<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caja_usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_id')->constrained('cajas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('asignado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['caja_id', 'user_id']);
        });

        Schema::table('pagos_ordenes', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('caja_id')->constrained('users')->nullOnDelete();
            $table->index('user_id');
        });

        // Conserva la trazabilidad de los cobros históricos con el responsable de su caja.
        DB::table('pagos_ordenes')
            ->join('cajas', 'cajas.id', '=', 'pagos_ordenes.caja_id')
            ->whereNull('pagos_ordenes.user_id')
            ->update(['pagos_ordenes.user_id' => DB::raw('cajas.user_id')]);

        DB::table('cajas')->whereNotNull('user_id')->orderBy('id')->each(function ($caja) {
            DB::table('caja_usuarios')->updateOrInsert(
                ['caja_id' => $caja->id, 'user_id' => $caja->user_id],
                ['asignado_por' => $caja->user_id, 'created_at' => now(), 'updated_at' => now()]
            );
        });
    }

    public function down(): void
    {
        Schema::table('pagos_ordenes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::dropIfExists('caja_usuarios');
    }
};
