<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('insumos_modificador', function (Blueprint $table) {
   $table->id(); $table->string('nombre'); $table->integer('stock')->default(0); $table->integer('stock_minimo')->nullable(); $table->timestamps();
  });
  Schema::table('modificador_opciones', function (Blueprint $table) {
   $table->foreignId('insumo_modificador_id')->nullable()->after('modificador_id')->constrained('insumos_modificador')->nullOnDelete();
  });
 }
 public function down(): void { Schema::table('modificador_opciones', fn(Blueprint $t)=>$t->dropConstrainedForeignId('insumo_modificador_id')); Schema::dropIfExists('insumos_modificador'); }
};
