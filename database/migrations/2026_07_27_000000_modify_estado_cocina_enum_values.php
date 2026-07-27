<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ampliar los valores permitidos para estado_cocina sin cambiar el nombre de columna.
        // Se usa SQL directo para mayor compatibilidad con ENUM en MySQL/Postgres.
        DB::statement("ALTER TABLE `orden_detalles` MODIFY `estado_cocina` ENUM('pendiente','en_preparacion','listo_para_recoger','recogido','servido') NOT NULL DEFAULT 'pendiente'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Volver al conjunto anterior de valores (pendiente, servido).
        DB::statement("ALTER TABLE `orden_detalles` MODIFY `estado_cocina` ENUM('pendiente','servido') NOT NULL DEFAULT 'pendiente'");
    }
};
