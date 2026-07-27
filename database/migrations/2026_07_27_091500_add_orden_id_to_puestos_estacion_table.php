<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puestos_estacion', function (Blueprint $table) {
            $table->foreignId('orden_id')->nullable()->constrained('ordenes')->nullOnDelete()->after('user_id');
            $table->unique(['orden_id']);
        });
    }

    public function down(): void
    {
        Schema::table('puestos_estacion', function (Blueprint $table) {
            $table->dropUnique(['orden_id']);
            $table->dropConstrainedForeignId('orden_id');
        });
    }
};
