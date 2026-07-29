<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ReestablecerContrasenaController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\EstacionTrabajoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CocinaController;
use App\Http\Controllers\HistorialCambioOrdenController;
use App\Http\Controllers\MesaController;
use App\Http\Controllers\ModificadorController;
use App\Http\Controllers\PuestoEstacionController;
use App\Http\Controllers\OrdenController;
use App\Http\Controllers\PagoOrdenController;
use App\Http\Controllers\ProductoController;
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
    Route::apiResource('estaciones-trabajo', EstacionTrabajoController::class);
    Route::apiResource('modificadores', ModificadorController::class);
    Route::post('productos/{producto}/stock-adjust', [ProductoController::class, 'ajustarStock']);
    Route::apiResource('productos', ProductoController::class);
    Route::apiResource('mesas', MesaController::class);
    Route::get('clientes/search', [ClienteController::class, 'search']);
    Route::apiResource('clientes', ClienteController::class);
    Route::get('ordenes/{orden}/historial', [HistorialCambioOrdenController::class, 'index']);
    Route::get('cocina/pedidos', [CocinaController::class, 'pedidos']);
    Route::get('cocina/control', [PuestoEstacionController::class, 'control']);
    Route::patch('cocina/detalles/{detalle}', [CocinaController::class, 'actualizarDetalle']);
    Route::get('cocina/monitor/puestos', [PuestoEstacionController::class, 'index']);
    Route::post('cocina/control/puestos/{puesto}/ocupar', [PuestoEstacionController::class, 'ocupar']);
    Route::post('cocina/control/puestos/{puesto}/liberar', [PuestoEstacionController::class, 'liberar']);
    Route::post('cocina/control/puestos/{puesto}/asignar-orden', [PuestoEstacionController::class, 'asignarOrden']);
    Route::post('cocina/control/puestos/{puesto}/liberar-orden', [PuestoEstacionController::class, 'liberarOrden']);
    Route::post('cocina/control/puestos/{puesto}/orden/lista', [PuestoEstacionController::class, 'ordenarLista']);
    Route::get('cajas/actual', [CajaController::class, 'actual']);
    Route::post('cajas/abrir', [CajaController::class, 'abrir']);
    Route::post('cajas/{caja}/cerrar', [CajaController::class, 'cerrar']);
    Route::apiResource('cajas', CajaController::class)->only(['index', 'show', 'update', 'destroy']);
    Route::apiResource('ordenes', OrdenController::class);
    Route::apiResource('pagos-ordenes', PagoOrdenController::class);
    Route::get('/me', [AuthController::class, 'getUser']);
});
