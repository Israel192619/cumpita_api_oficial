<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $bebidasId = DB::table('estaciones_trabajo')->where('codigo', 'BEBIDAS')->value('id');
        $meserosId = DB::table('estaciones_trabajo')->where('codigo', 'MESEROS')->value('id');

        if (!$bebidasId || !$meserosId) return;

        DB::table('productos')->where('estacion_id', $bebidasId)->update(['estacion_id' => $meserosId]);
        DB::table('modificadores')->where('estacion_id', $bebidasId)->update(['estacion_id' => $meserosId]);
        DB::table('users')->where('estacion_id', $bebidasId)->update(['estacion_id' => $meserosId]);
        DB::table('estaciones_trabajo')->where('id', $bebidasId)->update(['activa' => false]);
    }

    public function down(): void
    {
        DB::table('estaciones_trabajo')->where('codigo', 'BEBIDAS')->update(['activa' => true]);
    }
};
