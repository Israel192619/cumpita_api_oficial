<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->uuid('operacion_cliente_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropUnique(['operacion_cliente_id']);
            $table->dropColumn('operacion_cliente_id');
        });
    }
};
