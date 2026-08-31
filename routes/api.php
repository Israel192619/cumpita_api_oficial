<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\AjusteStockController;
use App\Http\Controllers\Auth\ReestablecerContrasenaController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\EstacionTrabajoController;
use App\Http\Controllers\GastoCajaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CocinaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HistorialCambioOrdenController;
use App\Http\Controllers\MesaController;
use App\Http\Controllers\ModificadorController;
use App\Http\Controllers\MovimientoCajaController;
use App\Http\Controllers\OrdenController;
use App\Http\Controllers\OrdenAdicionalController;
use App\Http\Controllers\PagoOrdenController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ReservaStockController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\ServicioController;
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
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'getUser']);

    Route::middleware('access:admin')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('reportes/ventas', [ReporteController::class, 'ventas']);
        Route::get('reportes/productos', [ReporteController::class, 'productos']);
        Route::get('reportes/caja', [ReporteController::class, 'caja']);
        Route::apiResource('roles', RoleController::class);
        Route::apiResource('users', UserController::class);
        Route::apiResource('estaciones-trabajo', EstacionTrabajoController::class);
        Route::apiResource('modificadores', ModificadorController::class);
        Route::apiResource('categorias', CategoriaController::class)->except(['index', 'show']);
        Route::apiResource('productos', ProductoController::class)->except(['index', 'show']);
        Route::get('ajustes-stock', [AjusteStockController::class, 'index']);
        Route::post('ajustes-stock', [AjusteStockController::class, 'store']);
        Route::post('ajustes-stock/{ajuste}/revertir', [AjusteStockController::class, 'revertir']);
        // El formulario de edición consulta GET /mesas/{mesa}; no excluir show.
        Route::apiResource('mesas', MesaController::class)->except(['index']);
    });

    Route::middleware('access:captura-preorden')->group(function () {
        Route::get('categorias', [CategoriaController::class, 'index']);
        Route::get('categorias/{categoria}', [CategoriaController::class, 'show']);
        Route::get('productos', [ProductoController::class, 'index']);
        Route::get('productos/{producto}', [ProductoController::class, 'show']);
        Route::get('mesas', [MesaController::class, 'index']);
        Route::get('clientes/search', [ClienteController::class, 'search']);
        Route::post('clientes', [ClienteController::class, 'store']);
        Route::post('ordenes', [OrdenController::class, 'store']);
        Route::get('ordenes/{orden}', [OrdenController::class, 'show']);
        Route::match(['put', 'patch'], 'ordenes/{orden}', [OrdenController::class, 'update']);
    });

    Route::middleware('access:pos')->group(function () {
        Route::post('reservas-stock/sincronizar', [ReservaStockController::class, 'sincronizar']);
        Route::delete('reservas-stock', [ReservaStockController::class, 'liberar']);
        Route::post('productos/{producto}/stock-adjust', [ProductoController::class, 'ajustarStock']);
        Route::apiResource('clientes', ClienteController::class)->except(['store']);
        Route::get('ordenes/{orden}/historial', [HistorialCambioOrdenController::class, 'index']);
        Route::post('ordenes/{orden}/cancelar-venta', [OrdenController::class, 'cancelarVenta']);
        Route::post('ordenes/{orden}/activar-preorden', [OrdenController::class, 'activarPreorden']);
        Route::apiResource('ordenes', OrdenController::class)->only(['index', 'destroy']);
        Route::apiResource('pagos-ordenes', PagoOrdenController::class);
    });

    Route::middleware('access:kds')->group(function () {
        Route::get('cocina/pedidos', [CocinaController::class, 'pedidos']);
        Route::patch('cocina/detalles/{detalle}', [CocinaController::class, 'actualizarDetalle']);
        Route::get('kds/pedidos', [CocinaController::class, 'pedidos']);
        Route::get('kds/preordenes-proximas', [CocinaController::class, 'preordenesProximas']);
        Route::patch('kds/detalles/{detalle}', [CocinaController::class, 'actualizarDetalle']);
        Route::post('kds/sesion', [CocinaController::class, 'registrarSesion']);
    });

    Route::middleware('access:caja')->group(function () {
        Route::get('cajas/actual', [CajaController::class, 'actual']);
        Route::post('cajas/abrir', [CajaController::class, 'abrir']);
        Route::post('cajas/{caja}/cerrar', [CajaController::class, 'cerrar']);
        Route::get('cajas/{caja}/usuarios-disponibles', [CajaController::class, 'usuariosDisponibles']);
        Route::put('cajas/{caja}/usuarios', [CajaController::class, 'actualizarUsuarios']);
        Route::apiResource('cajas', CajaController::class)->only(['index', 'show', 'update', 'destroy']);
        Route::post('movimientos-caja/{movimiento}/anular', [MovimientoCajaController::class, 'anular']);
        Route::apiResource('movimientos-caja', MovimientoCajaController::class)->only(['index', 'store']);
        Route::post('gastos-caja/{gasto}/anular', [GastoCajaController::class, 'anular']);
        Route::apiResource('gastos-caja', GastoCajaController::class)->only(['index', 'store']);
    });

    Route::middleware('access:despacho')->group(function () {
        Route::get('/acceso-rapido/meseros', [AuthController::class, 'meserosAccesoRapido'])->middleware('throttle:30,1');
        Route::post('/acceso-rapido/pin', [AuthController::class, 'loginPin'])->middleware('throttle:10,1');
    });

    Route::middleware('access:servicio')->group(function () {
        Route::get('servicio/ordenes/buscar', [OrdenAdicionalController::class, 'buscar']);
        Route::get('servicio/ordenes/{orden}', [OrdenAdicionalController::class, 'show']);
        Route::post('servicio/ordenes/{orden}/adicionales', [OrdenAdicionalController::class, 'store']);
        Route::get('servicio/fichas', [ServicioController::class, 'index']);
        Route::post('servicio/sesion/cerrar', [ServicioController::class, 'cerrarSesion']);
        Route::post('servicio/fichas/{orden}/tomar', [ServicioController::class, 'tomar']);
        Route::post('servicio/fichas/{orden}/liberar', [ServicioController::class, 'liberar']);
        Route::patch('servicio/detalles/{detalle}/confirmar', [ServicioController::class, 'confirmarDetalle']);
        Route::post('servicio/fichas/{orden}/entregar', [ServicioController::class, 'entregar']);
    });
});
