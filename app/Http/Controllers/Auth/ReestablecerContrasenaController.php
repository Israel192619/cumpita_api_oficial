<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\ReestablecerContrasenaMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ReestablecerContrasenaController extends Controller
{
    public function olvideMiContrasena(Request $request)
    {
        $data = $request->validate([
            'identificador' => ['required', 'string', 'max:255'],
        ]);

        $identificador = Str::lower(trim($data['identificador']));
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$identificador])
            ->orWhereRaw('LOWER(username) = ?', [$identificador])
            ->first();

        // La respuesta no revela si el correo o usuario está registrado.
        if (!$user) {
            return response()->json([
                'message' => 'Si existe una cuenta asociada, se enviará un enlace de recuperación.'
            ], 200);
        }

        $token = Str::random(60);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($token),
                'created_at' => now()
            ]
        );

        Mail::to($user->email)
            ->send(new ReestablecerContrasenaMail($token, $user->email));

        return response()->json([
            'message' => 'Si existe una cuenta asociada, se enviará un enlace de recuperación.'
        ], 200);
    }

    public function reestablecerContrasena(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required',
            'password' => 'required|min:6|confirmed'
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$record || !Hash::check($request->token, $record->token)) {
            return response()->json(['message' => 'El enlace de recuperación no es válido o ya fue utilizado'], 400);
        }

        if (now()->diffInMinutes($record->created_at) > 60) {
            return response()->json(['message' => 'El enlace de recuperación ha expirado. Solicita uno nuevo.'], 400);
        }

        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        return response()->json([
            'message' => 'Contraseña actualizada'
        ],200);
    }
}
