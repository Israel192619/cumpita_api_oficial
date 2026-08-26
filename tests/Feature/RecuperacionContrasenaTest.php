<?php

namespace Tests\Feature;

use App\Mail\ReestablecerContrasenaMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RecuperacionContrasenaTest extends TestCase
{
    use RefreshDatabase;

    public function test_puede_solicitar_recuperacion_con_nombre_de_usuario(): void
    {
        Mail::fake();
        $user = User::create([
            'name' => 'Ana',
            'username' => 'ana.mesera',
            'email' => 'ana@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/olvide-mi-contrasena', [
            'identificador' => 'ANA.MESERA',
        ])->assertOk()->assertJson([
            'message' => 'Si existe una cuenta asociada, se enviará un enlace de recuperación.',
        ]);

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        Mail::assertSent(ReestablecerContrasenaMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_identificador_inexistente_devuelve_respuesta_generica_sin_enviar_correo(): void
    {
        Mail::fake();

        $this->postJson('/api/olvide-mi-contrasena', [
            'identificador' => 'usuario.inexistente',
        ])->assertOk()->assertJson([
            'message' => 'Si existe una cuenta asociada, se enviará un enlace de recuperación.',
        ]);

        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        Mail::assertNothingSent();
    }
}
