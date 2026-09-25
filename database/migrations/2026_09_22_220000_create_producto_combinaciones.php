<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_combinaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->string('nombre', 80);
            $table->boolean('activo')->default(true);
            $table->boolean('predeterminada')->default(false);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
            $table->unique(['producto_id', 'nombre']);
        });

        Schema::create('producto_combinacion_opciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_combinacion_id')->constrained('producto_combinaciones')->cascadeOnDelete();
            $table->foreignId('modificador_opcion_id')->constrained('modificador_opciones')->cascadeOnDelete();
            $table->unique(['producto_combinacion_id', 'modificador_opcion_id'], 'producto_combinacion_opcion_unique');
        });

        Schema::table('orden_detalles', function (Blueprint $table) {
            $table->foreignId('producto_combinacion_id')->nullable()->after('producto_id')
                ->constrained('producto_combinaciones')->nullOnDelete();
            $table->string('combinacion_nombre', 80)->nullable()->after('producto_combinacion_id');
        });
    }

    public function down(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('producto_combinacion_id');
            $table->dropColumn('combinacion_nombre');
        });
        Schema::dropIfExists('producto_combinacion_opciones');
        Schema::dropIfExists('producto_combinaciones');
    }
};
