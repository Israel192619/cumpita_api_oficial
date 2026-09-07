<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caja_usuarios', function (Blueprint $table) {
            $table->string('estado', 20)->default('aceptada')->after('asignado_por');
            $table->timestamp('respondida_en')->nullable()->after('estado');
        });
        DB::table('caja_usuarios')->update(['estado' => 'aceptada', 'respondida_en' => now()]);
    }

    public function down(): void
    {
        Schema::table('caja_usuarios', function (Blueprint $table) {
            $table->dropColumn(['estado', 'respondida_en']);
        });
    }
};
