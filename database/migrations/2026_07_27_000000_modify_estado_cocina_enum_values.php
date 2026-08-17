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
        // SQLite representa enum como texto y no admite ALTER ... MODIFY.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        // Ampliar los valores permitidos para estado_cocina sin cambiar el nombre de columna.
        // En producción MySQL se conserva el ENUM existente.
        DB::statement("ALTER TABLE `orden_detalles` MODIFY `estado_cocina` ENUM('pendiente','en_preparacion','listo_para_recoger','recogido','servido') NOT NULL DEFAULT 'pendiente'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        // Volver al conjunto anterior de valores (pendiente, servido).
        DB::statement("ALTER TABLE `orden_detalles` MODIFY `estado_cocina` ENUM('pendiente','servido') NOT NULL DEFAULT 'pendiente'");
    }
};
