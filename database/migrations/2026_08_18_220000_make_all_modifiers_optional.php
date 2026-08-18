<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('modificadores')->where('requerido', true)->update(['requerido' => false]);
    }

    public function down(): void
    {
        // No se puede reconstruir de forma segura cuáles grupos eran obligatorios.
    }
};
