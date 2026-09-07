<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('producto_modificador_configuraciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('modificador_id')->constrained('modificadores')->cascadeOnDelete();
            $table->unsignedSmallInteger('cantidad_requerida')->nullable();
            $table->timestamps();
            $table->unique(['producto_id', 'modificador_id'], 'producto_modificador_config_unique');
        });

        if (Schema::hasTable('insumos_modificador') && Schema::hasColumn('modificador_opciones', 'insumo_modificador_id')) {
            DB::table('modificador_opciones')->whereNotNull('insumo_modificador_id')->orderBy('id')->get()->each(function ($opcion) {
                $insumo = DB::table('insumos_modificador')->where('id', $opcion->insumo_modificador_id)->first();
                if ($insumo) DB::table('modificador_opciones')->where('id', $opcion->id)->update([
                    'maneja_stock' => true, 'stock' => $insumo->stock, 'stock_minimo' => $insumo->stock_minimo,
                ]);
            });
            Schema::dropIfExists('ajustes_stock_modificador');
            Schema::table('modificador_opciones', fn (Blueprint $table) => $table->dropConstrainedForeignId('insumo_modificador_id'));
            Schema::dropIfExists('insumos_modificador');
        }

        if (Schema::hasColumn('modificadores', 'cantidad_requerida')) {
            DB::table('producto_opciones')->join('modificador_opciones', 'modificador_opciones.id', '=', 'producto_opciones.modificador_opcion_id')
                ->join('modificadores', 'modificadores.id', '=', 'modificador_opciones.modificador_id')
                ->whereNotNull('modificadores.cantidad_requerida')
                ->select('producto_opciones.producto_id', 'modificadores.id as modificador_id', 'modificadores.cantidad_requerida')->distinct()->get()
                ->each(fn ($fila) => DB::table('producto_modificador_configuraciones')->updateOrInsert(
                    ['producto_id' => $fila->producto_id, 'modificador_id' => $fila->modificador_id],
                    ['cantidad_requerida' => $fila->cantidad_requerida, 'created_at' => now(), 'updated_at' => now()]
                ));
        }

        Schema::table('ajustes_stock', function (Blueprint $table) {
            $table->foreignId('modificador_opcion_id')->nullable()->after('producto_id')->constrained('modificador_opciones');
        });
        Schema::table('ajustes_stock', fn (Blueprint $table) => $table->foreignId('producto_id')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('ajustes_stock', function (Blueprint $table) {
            $table->dropConstrainedForeignId('modificador_opcion_id');
            $table->foreignId('producto_id')->nullable(false)->change();
        });
        Schema::dropIfExists('producto_modificador_configuraciones');
    }
};
