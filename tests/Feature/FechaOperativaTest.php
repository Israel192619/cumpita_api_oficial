<?php

namespace Tests\Feature;

use App\Models\Orden;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FechaOperativaTest extends TestCase
{
    public function test_tableros_usan_fecha_de_trabajo_con_respaldo_para_ordenes_antiguas(): void
    {
        Schema::create('ordenes', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_flujo')->nullable();
            $table->string('estado_preorden')->nullable();
            $table->dateTime('preorden_activada_en')->nullable();
            $table->dateTime('fecha_orden')->nullable();
            $table->dateTime('created_at');
        });
        foreach ([1 => '2026-09-05 10:00:00', 2 => '2026-09-06 10:00:00', 3 => null, 4 => '2026-09-04 10:00:00'] as $id => $fecha) {
            DB::table('ordenes')->insert(['id' => $id, 'tipo_flujo' => 'normal', 'fecha_orden' => $fecha, 'created_at' => '2026-09-05 09:00:00']);
        }
        $this->assertSame([1, 3], Orden::deFechaOperativa('2026-09-05')->orderBy('id')->pluck('id')->all());
        $this->assertSame([2], Orden::deFechaOperativa('2026-09-06')->pluck('id')->all());
        $this->assertSame([4], Orden::deFechaOperativa('2026-09-04')->pluck('id')->all());
    }
}
