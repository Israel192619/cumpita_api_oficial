<?php

namespace App\Http\Controllers\Auth;

use App\Events\ServicioSesionActualizadaEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    private const SERVICIO_TTL_MINUTOS = 720;

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        try {
            $token = JWTAuth::fromUser($user);
        } catch (JWTException $e) {
            return response()->json(['message' => 'Could not create token'], 500);
        }

        return response()->json([
            'token' => $token,
            'user' => $user,
        ], 201);
    }

    public function login(Request $request)
    {
        // 1. Validamos solo dos cosas: el identificador (que puede ser email o usuario) y la clave
        $data = $request->validate([
            'identificador' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);


        $identificador = Str::lower(trim($data['identificador']));

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$identificador])
            ->orWhereRaw('LOWER(username) = ?', [$identificador])
            ->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        // 5. Generamos el token de seguridad
        try {
            $token = JWTAuth::fromUser($user);
        } catch (JWTException $e) {
            return response()->json(['message' => 'No se pudo crear el token de autenticación'], 500);
        }

        $auth = auth('api');

        /** @var \Tymon\JWTAuth\JWTGuard $auth */
        // 6. Enviamos la respuesta exitosa
        return response()->json([
            'token' => $token,
            'expires_in' => $auth->factory()->getTTL() * 60
        ]);
    }

    public function meserosAccesoRapido()
    {
        return response()->json([
            'meseros' => User::whereHas('role', fn ($query) => $query->whereRaw('LOWER(nombre) = ?', ['mesero']))
                ->whereNotNull('pin')
                ->with('perfilUsuarios:id,user_id,avatar')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function loginPin(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'pin' => ['required', 'digits_between:4,6'],
        ]);
        $user = User::whereKey($data['user_id'])
            ->whereHas('role', fn ($query) => $query->whereRaw('LOWER(nombre) = ?', ['mesero']))
            ->first();

        if (!$user || !$user->pin || !Hash::check($data['pin'], $user->pin)) {
            return response()->json(['message' => 'PIN incorrecto.'], 401);
        }

        $sessionId = (string) Str::uuid();
        JWTAuth::factory()->setTTL(self::SERVICIO_TTL_MINUTOS);
        $token = JWTAuth::claims([
            'scope' => 'servicio',
            'session_id' => $sessionId,
        ])->fromUser($user);
        $this->notificarSesion('sesion_iniciada', $user->id, $sessionId);

        return response()->json([
            'token' => $token,
            'session_id' => $sessionId,
            'expires_in' => self::SERVICIO_TTL_MINUTOS * 60,
            'user' => $user->load(['role', 'perfilUsuarios']),
        ]);
    }

    private function notificarSesion(string $tipo, int $userId, string $sessionId): void
    {
        try {
            event(new ServicioSesionActualizadaEvent($tipo, $userId, $sessionId));
        } catch (\Throwable $e) {
            Log::warning('No se pudo notificar la sesión de Servicio.', [
                'tipo' => $tipo, 'user_id' => $userId, 'error' => $e->getMessage(),
            ]);
        }
    }

    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
        } catch (JWTException $e) {
            return response()->json(['message' => 'No se pudo cerrar la sesión, por favor inténtalo de nuevo'], 500);
        }

        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }

    /**public function getUser()
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['message' => 'User not found'], 404);
            }

            // Load related perfil, estacion y rol para la UI de Cocina.
            $user->loadMissing(['perfilUsuarios', 'estacion', 'role']);

            return response()->json($user);
        } catch (JWTException $e) {
            return response()->json(['message' => 'Failed to fetch user profile'], 500);
        }
    }*/
    public function getUser()
    {
        try {
            // Obtenemos el ID del usuario autenticado de forma segura
            $userId = Auth::id();
            
            if (!$userId) {
                return response()->json(['message' => 'User not found'], 404);
            }

            // Buscamos el usuario desde el Modelo Eloquent usando el ID
            $user = User::findOrFail($userId);

            // Ahora loadMissing funcionará sin problemas
            $user->loadMissing(['perfilUsuarios', 'estacion', 'role']);

            return response()->json($user);
        } catch (JWTException $e) {
            return response()->json(['message' => 'Failed to fetch user profile'], 500);
        }
    }

    public function updateUser(Request $request)
    {
        try {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'min:3', 'max:50', 'regex:/^[a-zA-Z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'direccion' => ['nullable', 'string', 'max:255'],
            'numero_celular' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:8'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user->fill(collect($validated)->only(['name', 'username', 'email'])->all());
        if (! empty($validated['password'])) $user->password = Hash::make($validated['password']);
        $user->save();

        $perfil = $user->perfilUsuarios;
        $avatar = $perfil?->avatar;
        if ($request->hasFile('avatar')) {
            if ($avatar) Storage::disk('public')->delete($avatar);
            $avatar = $request->file('avatar')->store('avatars', 'public');
        }
        $user->perfilUsuarios()->updateOrCreate(['user_id' => $user->id], [
            'direccion' => $validated['direccion'] ?? null,
            'numero_celular' => $validated['numero_celular'] ?? null,
            'avatar' => $avatar,
        ]);

        return response()->json($user->fresh()->load(['perfilUsuarios', 'estacion', 'role']));

    } catch (JWTException $e) {
        return response()->json(['message' => 'Failed to update user'], 500);
    }
    }
}
