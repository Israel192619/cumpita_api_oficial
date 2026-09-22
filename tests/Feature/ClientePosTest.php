<?php

namespace Tests\Feature;

use App\Http\Controllers\ClienteController;
use App\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ClientePosTest extends TestCase
{
    use RefreshDatabase;

    public function test_reutiliza_cliente_por_nombre_sin_importar_mayusculas_o_espacios(): void
    {
        $existente = Cliente::create(['nombre' => 'Raquel']);
        $request = Request::create('/api/clientes', 'POST', ['nombre' => '  RAQUEL  ']);

        $response = app(ClienteController::class)->store($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($existente->id, $response->getData(true)['cliente']['id']);
        $this->assertDatabaseCount('clientes', 1);
    }
}
