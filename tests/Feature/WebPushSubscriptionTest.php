<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class WebPushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_parrillero_registra_un_solo_dispositivo_y_recibe_la_clave_publica(): void
    {
        config(['webpush.vapid.public_key' => 'clave-publica-prueba']);
        $role = Role::create(['nombre' => 'Cocinero']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $token = JWTAuth::fromUser($user);
        $payload = [
            'channel' => 'PARRILLA',
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/dispositivo-1',
            'keys' => ['p256dh' => 'clave-dispositivo', 'auth' => 'token-dispositivo'],
        ];

        $this->withToken($token)->getJson('/api/web-push/public-key')
            ->assertOk()->assertJsonPath('public_key', 'clave-publica-prueba');
        $this->withToken($token)->postJson('/api/web-push/subscriptions', $payload)
            ->assertOk()->assertJsonPath('active', true);
        $this->withToken($token)->postJson('/api/web-push/subscriptions', $payload)
            ->assertOk();

        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $this->assertDatabaseHas('web_push_subscriptions', [
            'user_id' => $user->id,
            'station_code' => 'PARRILLA',
            'endpoint_hash' => hash('sha256', $payload['endpoint']),
        ]);
    }

    public function test_mesero_puede_registrar_avisos_de_servicio(): void
    {
        $role = Role::create(['nombre' => 'Mesero']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->withToken(JWTAuth::fromUser($user))->postJson('/api/web-push/subscriptions', [
            'channel' => 'SERVICIO',
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/mesero-1',
            'keys' => ['p256dh' => 'clave-mesero', 'auth' => 'token-mesero'],
        ])->assertOk();

        $this->assertDatabaseHas('web_push_subscriptions', [
            'user_id' => $user->id,
            'station_code' => 'SERVICIO',
        ]);
    }

    public function test_version_anterior_de_parrilla_se_registra_sin_channel(): void
    {
        $role = Role::create(['nombre' => 'Cocinero']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->withToken(JWTAuth::fromUser($user))->postJson('/api/web-push/subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/parrilla-anterior',
            'keys' => ['p256dh' => 'clave-anterior', 'auth' => 'token-anterior'],
        ])->assertOk();

        $this->assertDatabaseHas('web_push_subscriptions', ['station_code' => 'PARRILLA']);
    }
}
