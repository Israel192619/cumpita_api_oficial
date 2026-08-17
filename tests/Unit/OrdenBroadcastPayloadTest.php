<?php

namespace Tests\Unit;

use App\Events\OrdenCocinaActualizadaEvent;
use App\Events\OrdenCreadaEvent;
use App\Events\ServicioSesionActualizadaEvent;
use App\Models\Orden;
use PHPUnit\Framework\TestCase;

class OrdenBroadcastPayloadTest extends TestCase
{
    public function test_orden_creada_solo_publica_identificador_y_tipo(): void
    {
        $orden = new Orden();
        $orden->id = 125;

        $payload = (new OrdenCreadaEvent($orden))->broadcastWith();

        $this->assertSame(['tipo' => 'orden_creada', 'orden_id' => 125], $payload);
        $this->assertLessThan(100, strlen(json_encode($payload)));
    }

    public function test_orden_actualizada_solo_publica_identificador_y_tipo(): void
    {
        $orden = new Orden();
        $orden->id = 125;

        $payload = (new OrdenCocinaActualizadaEvent($orden, range(1, 100)))->broadcastWith();

        $this->assertSame(['tipo' => 'orden_actualizada', 'orden_id' => 125], $payload);
        $this->assertLessThan(100, strlen(json_encode($payload)));
    }

    public function test_sesion_de_servicio_publica_solo_identificadores_minimos(): void
    {
        $payload = (new ServicioSesionActualizadaEvent('sesion_iniciada', 7, 'sesion-123'))->broadcastWith();

        $this->assertSame([
            'tipo' => 'sesion_iniciada', 'user_id' => 7, 'session_id' => 'sesion-123',
        ], $payload);
        $this->assertLessThan(120, strlen(json_encode($payload)));
    }
}
