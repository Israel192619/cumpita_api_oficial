<?php
namespace Tests\Feature;

use App\Events\PreordenActualizadaEvent;
use App\Models\Orden;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PreordenRealtimeTest extends TestCase
{
    public function test_notifica_sin_worker_y_solo_despues_de_confirmar_la_transaccion(): void
    {
        Queue::fake();
        $avisos = [];
        $bus = \Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatchNow')->andReturnUsing(function ($job) use (&$avisos) {
            $this->assertInstanceOf(BroadcastEvent::class, $job);
            $avisos[] = $job->event->broadcastWith();
        });
        $this->app->instance(Dispatcher::class, $bus);
        $orden = new Orden();
        $orden->id = 123;
        DB::beginTransaction();
        event(new PreordenActualizadaEvent($orden, 'preorden_creada'));
        $this->assertSame([], $avisos);
        DB::commit();
        $this->assertSame([['tipo' => 'preorden_creada', 'orden_id' => 123]], $avisos);
        Queue::assertNothingPushed();

        DB::beginTransaction();
        event(new PreordenActualizadaEvent($orden, 'preorden_activada'));
        DB::rollBack();
        $this->assertCount(1, $avisos);
        Queue::assertNothingPushed();
    }

    public function test_fallo_de_reverb_se_reporta_sin_interrumpir_la_operacion(): void
    {
        $error = new \RuntimeException('Reverb no disponible en la prueba');
        $bus = \Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatchNow')->once()->andThrow($error);
        $this->app->instance(Dispatcher::class, $bus);
        $handler = \Mockery::mock(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($error);
        $this->app->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, $handler);
        $orden = new Orden();
        $orden->id = 124;
        event(new PreordenActualizadaEvent($orden, 'preorden_creada'));
        $this->assertTrue(true); // El error fue reportado sin propagarse al controlador.
    }
}
