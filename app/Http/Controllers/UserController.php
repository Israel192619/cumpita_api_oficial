<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $userActual = auth('api')->user();
        $users = User::where('id', '!=', $userActual->id)->with('perfilUsuarios', 'estacion')->get();
        return response()->json([
            'users' => $users
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'username' => ['nullable', 'string', 'min:3', 'max:50', 'regex:/^[a-zA-Z0-9._-]+$/', 'unique:users,username'],
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'pin' => 'nullable|digits_between:4,6',
            'role_id' => 'required|exists:roles,id',
            'estacion_id' => [
                'nullable',
                Rule::exists('estaciones_trabajo', 'id')->where(fn ($query) => $query->where('activa', true)),
            ],
            'direccion' => 'nullable|string',
            'numero_celular' => 'nullable|string',
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        DB::beginTransaction();
        
        try{
            $esMesero = Role::whereKey($request->role_id)
                ->whereRaw('LOWER(nombre) = ?', ['mesero'])->exists();
            $datosUsuario = [
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'pin' => $esMesero && $request->filled('pin') ? Hash::make($request->pin) : null,
                'role_id' => $request->role_id,
                'estacion_id' => $request->has('estacion_id') ? ($request->estacion_id ?: null) : null,
            ];
            if ($request->filled('username')) {
                $datosUsuario['username'] = Str::lower(trim($request->username));
            }
            $user = User::create($datosUsuario);

            $avatarPath = null;

            if($request->hasFile('avatar')){
                $avatarPath = $request->file('avatar')->store('avatars', 'public');
            }

            $user->perfilUsuarios()->create([
                'numero_celular' => $request->numero_celular,
                'direccion' => $request->direccion,
                'avatar' => $avatarPath,
            ]);
            DB::commit();
            return response()->json([
                'message' => 'Usuario creado correctamente',
                'user' => $user->load('perfilUsuarios', 'estacion'),
            ], 201);
        } catch (\Exception $e){
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear usuario',
                'error' => $e->getMessage()
            ],500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = User::with('perfilUsuarios', 'estacion')->findOrFail($id);
        if (!$user) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }
        return response()->json([
            'user' => $user
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'username' => [
                'nullable', 'string', 'min:3', 'max:50', 'regex:/^[a-zA-Z0-9._-]+$/',
                Rule::unique('users', 'username')->ignore($id),
            ],
            'email' => "required|email|unique:users,email,$id",
            'password' => 'nullable|min:8',
            'pin' => 'nullable|digits_between:4,6',
            'role_id' => 'required|exists:roles,id',
            'estacion_id' => [
                'nullable',
                Rule::exists('estaciones_trabajo', 'id')->where(fn ($query) => $query->where('activa', true)),
            ],

            'direccion' => 'required|string|max:255',
            'numero_celular' => 'required|string|max:20',

            'avatar' => 'nullable|image|mimes:jpg,jpeg,png|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            $user = User::findOrFail($id);
            if (!$user) {
                return response()->json(['message' => 'Usuario no encontrado'], 404);
            }
            $datosUsuario = [
                'name' => $request->name,
                'email' => $request->email,
                'role_id' => $request->role_id,
                'estacion_id' => $request->has('estacion_id') ? ($request->estacion_id ?: null) : $user->estacion_id,
            ];
            if ($request->filled('username')) {
                $datosUsuario['username'] = Str::lower(trim($request->username));
            }
            $user->update($datosUsuario);
            $esMesero = Role::whereKey($request->role_id)
                ->whereRaw('LOWER(nombre) = ?', ['mesero'])->exists();
            if ($request->filled('password')) {
                $user->update([
                    'password' => Hash::make($request->password),
                ]);
            }
            if (!$esMesero) {
                $user->update(['pin' => null]);
            } elseif ($request->filled('pin')) {
                $user->update(['pin' => Hash::make($request->pin)]);
            }
            $profile = $user->perfilUsuarios;
            if ($request->hasFile('avatar')) {

                if ($profile && $profile->avatar) {
                    Storage::disk('public')->delete($profile->avatar);
                }
                $avatarPath = $request->file('avatar')->store('avatars', 'public');

            } else {
                $avatarPath = $profile?->avatar;
            }
            $user->perfilUsuarios()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'numero_celular' => $request->numero_celular,
                    'direccion' => $request->direccion,
                    'avatar' => $avatarPath,
                ]
            );

            DB::commit();

            return response()->json([
                'message' => 'Usuario actualizado correctamente',
                'data' => $user->load('perfilUsuarios', 'estacion')
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al actualizar usuario',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        DB::beginTransaction();
        try {
            $user = User::with('perfilUsuarios')->findOrFail($id);
            if (!$user) {
                return response()->json(['message' => 'Usuario no encontrado'], 404);
            }
            if ($user->perfilUsuarios && $user->perfilUsuarios->avatar) {
                Storage::disk('public')->delete($user->perfilUsuarios->avatar);
            }
            $user->delete();

            DB::commit();
            return response()->json([
                'message' => 'Usuario eliminado correctamente'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al eliminar usuario',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
