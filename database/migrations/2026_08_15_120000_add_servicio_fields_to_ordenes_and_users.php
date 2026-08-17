<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin')->nullable()->after('password');
        });
        Schema::table('ordenes', function (Blueprint $table) {
            $table->foreignId('mesero_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('tomada_en')->nullable()->after('mesero_id');
            $table->timestamp('entregada_en')->nullable()->after('tomada_en');
            $table->index(['mesero_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropIndex(['mesero_id', 'estado']);
            $table->dropForeign(['mesero_id']);
            $table->dropColumn(['mesero_id', 'tomada_en', 'entregada_en']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('pin'));
    }
};
