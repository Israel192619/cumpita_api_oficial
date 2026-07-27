<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
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
        $credentials = $request->only('email', 'password');

        try {
            if (!$token = JWTAuth::attempt($credentials)) {
                return response()->json(['message' => 'Credenciales Inválidas'], 401);
            }
        } catch (JWTException $e) {
            return response()->json(['message' => 'Could not create token'], 500);
        }
        /** @var \Tymon\JWTAuth\JWTGuard $auth */
        $auth = auth('api');

        return response()->json([
            'token' => $token,
            'expires_in' => $auth->factory()->getTTL() * 60
        ]);
    }

    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
        } catch (JWTException $e) {
            return response()->json(['message' => 'Failed to logout, please try again'], 500);
        }

        return response()->json(['message' => 'Successfully logged out']);
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

    public function updateUser(Request $request){
    try {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $user->update($request->only(['name', 'email']));

        return response()->json($user);

    } catch (JWTException $e) {
        return response()->json(['message' => 'Failed to update user'], 500);
    }
}
}
