<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $usedByDay = [];
        $maxByDay = [];

        DB::table('ordenes')
            ->select(['id', 'numero_orden', 'fecha_orden', 'created_at'])
            ->orderBy('id')
            ->get()
            ->each(function ($orden) use (&$usedByDay, &$maxByDay) {
                $day = Carbon::parse($orden->fecha_orden ?? $orden->created_at)->toDateString();
                $number = (int) $orden->numero_orden;
                $usedByDay[$day] ??= [];
                $maxByDay[$day] = max($maxByDay[$day] ?? 0, $number);

                if (isset($usedByDay[$day][$number])) {
                    do {
                        $number = ++$maxByDay[$day];
                    } while (isset($usedByDay[$day][$number]));

                    DB::table('ordenes')->where('id', $orden->id)->update(['numero_orden' => $number]);
                }

                $usedByDay[$day][$number] = true;
            });
    }

    public function down(): void
    {
        // Los números corregidos no se revierten porque volvería a crear duplicados.
    }
};
