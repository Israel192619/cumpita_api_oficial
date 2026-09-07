<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $principal = DB::table('modificadores')->whereRaw('LOWER(nombre) = ?', ['primera presa'])->first();
        $secundario = DB::table('modificadores')->whereRaw('LOWER(nombre) = ?', ['segunda presa'])->first();
        if (!$principal) return;

        $opcionesPrincipal = DB::table('modificador_opciones')->where('modificador_id', $principal->id)->pluck('id');
        $opcionesSecundario = $secundario
            ? DB::table('modificador_opciones')->where('modificador_id', $secundario->id)->pluck('id')
            : collect();
        $productosPrincipal = DB::table('producto_opciones')->whereIn('modificador_opcion_id', $opcionesPrincipal)->pluck('producto_id')->unique();
        $productosSecundario = DB::table('producto_opciones')->whereIn('modificador_opcion_id', $opcionesSecundario)->pluck('producto_id')->unique();

        foreach ($productosPrincipal->merge($productosSecundario)->unique() as $productoId) {
            $cantidad = $productosSecundario->contains($productoId) ? 2 : 1;
            DB::table('producto_modificador_configuraciones')->updateOrInsert(
                ['producto_id' => $productoId, 'modificador_id' => $principal->id],
                ['cantidad_requerida' => $cantidad, 'created_at' => now(), 'updated_at' => now()]
            );
            foreach ($opcionesPrincipal as $opcionId) DB::table('producto_opciones')->updateOrInsert(
                ['producto_id' => $productoId, 'modificador_opcion_id' => $opcionId],
                ['predeterminado' => false, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        if ($secundario) {
            DB::table('producto_opciones')->whereIn('modificador_opcion_id', $opcionesSecundario)->delete();
            DB::table('producto_modificador_configuraciones')->where('modificador_id', $secundario->id)->delete();
            // Se conserva para no borrar opciones usadas por órdenes históricas.
            DB::table('modificadores')->where('id', $secundario->id)->update(['activo' => false, 'updated_at' => now()]);
        }
        DB::table('modificadores')->where('id', $principal->id)->update([
            'nombre' => 'Presa de pollo', 'tipo' => 'multiple', 'requerido' => true, 'updated_at' => now(),
        ]);
    }

    public function down(): void {}
};
