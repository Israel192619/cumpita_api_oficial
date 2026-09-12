<?php

namespace Tests\Feature;

use App\Events\ConfiguracionActualizada;
use App\Http\Middleware\JwtMiddleware;
use App\Models\Role;
use App\Models\User;
use App\Services\ConfiguracionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ConfiguracionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Este módulo solo necesita su tabla; no depende de migraciones de caja.
        $migration = require database_path('migrations/2026_09_05_000001_create_configuraciones_table.php');
        $migration->up();
        $ubicacionMigration = require database_path('migrations/2026_09_11_000002_create_ubicacion_restaurante_table.php');
        $ubicacionMigration->up();
    }

    public function test_admin_can_save_restaurant_location_and_cashier_can_read_it(): void
    {
        Event::fake([ConfiguracionActualizada::class]);
        $this->withoutMiddleware(JwtMiddleware::class);
        $admin = (new User)->setRelation('role', new Role(['nombre' => 'Admin']));
        $cashier = (new User)->setRelation('role', new Role(['nombre' => 'Cajero']));

        $this->actingAs($admin, 'api')->putJson('/api/configuracion', [
            'restaurante' => ['latitud' => -17.3935, 'longitud' => -66.1570],
        ])->assertOk()->assertJsonPath('restaurante.latitud', -17.3935);

        $this->actingAs($cashier, 'api')->getJson('/api/configuracion')
            ->assertOk()->assertJsonPath('restaurante.longitud', -66.157);
    }

    public function test_admin_can_save_and_cashier_can_only_read_settings(): void
    {
        Event::fake([ConfiguracionActualizada::class]);
        $this->withoutMiddleware(JwtMiddleware::class);
        $admin = (new User)->setRelation('role', new Role(['nombre' => 'Admin']));
        $cashier = (new User)->setRelation('role', new Role(['nombre' => 'Cajero']));
        $this->actingAs($admin, 'api')->putJson('/api/configuracion', ['pos' => ['editar_fecha_trabajo' => false]])
            ->assertOk()->assertJsonPath('pos.editar_fecha_trabajo', false);
        Event::assertDispatchedTimes(ConfiguracionActualizada::class, 1);
        $this->actingAs($cashier, 'api')->getJson('/api/configuracion')
            ->assertOk()->assertJsonPath('pos.editar_fecha_trabajo', false);
        $this->actingAs($cashier, 'api')->putJson('/api/configuracion', ['pos' => ['editar_fecha_trabajo' => true]])->assertForbidden();
        Event::assertDispatchedTimes(ConfiguracionActualizada::class, 1);
    }

    public function test_disabled_setting_replaces_new_work_date_and_ignores_edits_without_changing_preorder(): void
    {
        DB::table('configuraciones')->insert(['clave' => 'pos.editar_fecha_trabajo', 'valor' => false]);
        $this->travelTo(now()->setDate(2026, 9, 5)->setTime(10, 30, 0));
        $service = app(ConfiguracionService::class);
        $payload = ['fecha_orden' => '2020-01-01T00:00:00', 'fecha_programada' => '2026-09-06T12:00:00'];
        $create = Request::create('/', 'POST', $payload);
        $service->aplicarFechaTrabajo($create, true);
        $this->assertSame('2026-09-05T10:30:00', $create->input('fecha_orden'));
        $this->assertSame($payload['fecha_programada'], $create->input('fecha_programada'));
        $edit = Request::create('/', 'PUT', $payload);
        $service->aplicarFechaTrabajo($edit, false);
        $this->assertFalse($edit->has('fecha_orden'));
        $this->assertSame($payload['fecha_programada'], $edit->input('fecha_programada'));
    }

    public function test_enabled_setting_preserves_selected_date(): void
    {
        $request = Request::create('/', 'POST', ['fecha_orden' => '2026-09-01T09:15:00']);
        app(ConfiguracionService::class)->aplicarFechaTrabajo($request, true);
        $this->assertSame('2026-09-01T09:15:00', $request->input('fecha_orden'));
    }
}
