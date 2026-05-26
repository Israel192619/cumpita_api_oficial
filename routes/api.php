<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ReestablecerContrasenaController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ModificadorController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/', function () {
    return response()->json(['message' => 'Hello world!']);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/olvide-mi-contrasena', [ReestablecerContrasenaController::class, 'olvideMiContrasena']);
Route::post('/reestablecer-contrasena', [ReestablecerContrasenaController::class, 'reestablecerContrasena']);

Route::middleware('jwt')->group(function () {
    // Route::get('/user', [AuthController::class, 'getUser']);
    // Route::put('/user', [AuthController::class, 'updateUser']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::apiResource('roles', RoleController::class);
    Route::apiResource('users', UserController::class);
    Route::apiResource('categorias', CategoriaController::class);
    Route::apiResource('modificadores', ModificadorController::class);
});