<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_instalacion_limpia_ejecuta_todos_los_seeders_sin_duplicar_perfiles(): void
    {
        $this->seed();
        // Una segunda ejecución debe actualizar, no duplicar, los datos base.
        $this->seed();

        $this->assertSame(User::count(), User::whereHas('perfilUsuarios')->count() + 1);
        $this->assertDatabaseCount('roles', 5);
        $this->assertDatabaseCount('estaciones_trabajo', 4);
        $this->assertDatabaseCount('users', 7);
        $this->assertDatabaseCount('perfil_usuarios', 6);
        $this->assertDatabaseCount('categorias', 11);
        $this->assertDatabaseCount('mesas', 15);
        $this->assertDatabaseCount('modificadores', 3);
        $this->assertDatabaseCount('modificador_opciones', 12);
        $this->assertDatabaseCount('productos', 9);
        $this->assertDatabaseCount('producto_opciones', 50);
        $this->assertDatabaseCount('puestos_estacion', 2);
        $this->assertDatabaseHas('users', ['username' => 'admin']);
        $this->assertDatabaseHas('users', ['username' => 'despacho']);
        $this->assertDatabaseHas('productos', ['id' => 11, 'nombre' => 'Jugo grande', 'stock' => 40]);
        $this->assertDatabaseHas('mesas', ['id' => 15, 'numero' => '16', 'capacidad' => 3]);

        $mesero = User::where('username', 'sam546')->with(['role', 'estacion'])->firstOrFail();
        $this->assertSame('Sam', $mesero->name);
        $this->assertSame('Mesero', $mesero->role?->nombre);
        $this->assertSame('MESEROS', $mesero->estacion?->codigo);
    }
}
