<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ordenes')
            ->join('clientes', 'clientes.id', '=', 'ordenes.cliente_id')
            ->whereNotNull('ordenes.observaciones')
            ->select('ordenes.id as orden_id', 'ordenes.observaciones', 'clientes.nombre')
            ->orderBy('ordenes.id')
            ->chunk(500, function ($ordenes): void {
                $ids = $ordenes->filter(fn ($orden) =>
                    mb_strtolower(trim((string) $orden->observaciones))
                    === mb_strtolower('Cliente: ' . trim((string) $orden->nombre))
                )->pluck('orden_id');

                if ($ids->isNotEmpty()) {
                    DB::table('ordenes')->whereIn('id', $ids)->update(['observaciones' => null]);
                }
            });
    }

    public function down(): void
    {
        // El texto automático no se restaura porque no era un comentario del usuario.
    }
};
