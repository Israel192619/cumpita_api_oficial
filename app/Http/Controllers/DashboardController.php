<?php

namespace App\Http\Controllers;

use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\OrdenDetalleEstacion;
use App\Models\PagoOrden;
use App\Models\Producto;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\GastoCaja;
use App\Models\MovimientoCaja;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private const ESTADOS_OPERATIVOS = ['pendiente', 'preparando', 'listo'];
    private const ESTADOS_KDS_PENDIENTES = ['pendiente', 'en_preparacion', 'listo_para_recoger'];

    public function index(Request $request)
    {
        $data = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
            'subcategoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
        ]);
        $desde = Carbon::createFromFormat('Y-m-d', $data['desde'])->startOfDay();
        $hasta = Carbon::createFromFormat('Y-m-d', $data['hasta'])->endOfDay();

        $pagos = PagoOrden::query()->whereBetween('fecha_pago', [$desde, $hasta]);
        $ventaTotal = (float) (clone $pagos)->sum('monto_pagado');
        $qr = (float) (clone $pagos)->where('metodo_pago', 'qr')->sum('monto_pagado');
        $efectivo = (float) (clone $pagos)->where('metodo_pago', 'efectivo')->sum('monto_pagado');
        $cobrosEfectivo = (float) (clone $pagos)->where('metodo_pago', 'efectivo')->where('tipo_pago', 'pago')->sum('monto_pagado');
        $devolucionesEfectivo = abs((float) (clone $pagos)->where('metodo_pago', 'efectivo')->where('tipo_pago', 'devolucion')->sum('monto_pagado'));
        $cobrosQr = (float) (clone $pagos)->where('metodo_pago', 'qr')->where('tipo_pago', 'pago')->sum('monto_pagado');
        $devolucionesQr = abs((float) (clone $pagos)->where('metodo_pago', 'qr')->where('tipo_pago', 'devolucion')->sum('monto_pagado'));
        $devolucionesTotal = $devolucionesEfectivo + $devolucionesQr;

        $cajasPeriodo = Caja::query()
            ->where('fecha_apertura', '<=', $hasta)
            ->where(fn (Builder $query) => $query->whereNull('fecha_cierre')->orWhere('fecha_cierre', '>=', $desde));
        $cajaIds = (clone $cajasPeriodo)->pluck('id');
        $montoApertura = (float) (clone $cajasPeriodo)->sum('monto_apertura');
        $ingresosCaja = (float) MovimientoCaja::query()->whereIn('caja_id', $cajaIds)
            ->where('estado', 'ACTIVO')->where('tipo', 'INGRESO')->whereBetween('created_at', [$desde, $hasta])->sum('monto');
        $retirosCaja = (float) MovimientoCaja::query()->whereIn('caja_id', $cajaIds)
            ->where('estado', 'ACTIVO')->where('tipo', 'RETIRO')->whereBetween('created_at', [$desde, $hasta])->sum('monto');
        $gastosCaja = (float) GastoCaja::query()->whereIn('caja_id', $cajaIds)
            ->where('estado', 'ACTIVO')->whereBetween('created_at', [$desde, $hasta])->sum('monto');
        $efectivoEsperado = $montoApertura + $efectivo + $ingresosCaja - $retirosCaja - $gastosCaja;
        $cantidadPagosQr = (clone $pagos)->where('metodo_pago', 'qr')->count();
        $cantidadOrdenes = (clone $pagos)->where('tipo_pago', 'pago')->distinct()->count('id_orden');
        $diasPeriodo = $desde->diffInDays($hasta) + 1;
        $hastaAnterior = $desde->copy()->subSecond();
        $desdeAnterior = $desde->copy()->subDays($diasPeriodo);
        $ventaAnterior = (float) PagoOrden::query()
            ->whereBetween('fecha_pago', [$desdeAnterior, $hastaAnterior])
            ->sum('monto_pagado');

        $ordenesPeriodo = Orden::query();
        $this->aplicarPeriodoOperativo($ordenesPeriodo, $desde, $hasta);
        $ordenesPeriodo->where('estado', '!=', 'cancelado')
            ->where(fn (Builder $query) => $query->where('tipo_flujo', '!=', 'preorden')
                ->orWhereNull('tipo_flujo')->orWhere('estado_preorden', 'activada'));

        $categoriaId = isset($data['categoria_id']) ? (int) $data['categoria_id'] : null;
        $subcategoriaId = isset($data['subcategoria_id']) ? (int) $data['subcategoria_id'] : null;
        $categoriaIds = null;
        if ($subcategoriaId) {
            $categoriaIds = [$subcategoriaId];
        } elseif ($categoriaId) {
            $categoriaIds = Categoria::query()->where('parent_id', $categoriaId)->pluck('id')->push($categoriaId);
        }

        $masVendidosQuery = OrdenDetalle::query()
            ->joinSub($ordenesPeriodo->select('ordenes.id'), 'ordenes_periodo', fn ($join) =>
                $join->on('orden_detalles.orden_id', '=', 'ordenes_periodo.id'))
            ->join('productos', 'productos.id', '=', 'orden_detalles.producto_id');
        if ($categoriaIds !== null) {
            $masVendidosQuery->whereIn('productos.categoria_id', $categoriaIds);
        }
        $masVendidos = $masVendidosQuery
            ->groupBy('productos.id', 'productos.nombre')
            ->orderByDesc(DB::raw('SUM(orden_detalles.cantidad)'))
            ->limit(5)
            ->get([
                'productos.id', 'productos.nombre',
                DB::raw('SUM(orden_detalles.cantidad) as cantidad'),
            ]);

        $ordenesOperativas = Orden::query()->operativas()
            ->whereIn('estado', self::ESTADOS_OPERATIVOS);
        $this->aplicarPeriodoOperativo($ordenesOperativas, $desde, $hasta);

        return response()->json([
            'periodo' => ['desde' => $data['desde'], 'hasta' => $data['hasta']],
            'kpis' => [
                'venta_total' => round($ventaTotal, 2),
                'qr' => round($qr, 2),
                'efectivo' => round($efectivo, 2),
                'cobros_efectivo' => round($cobrosEfectivo, 2),
                'devoluciones_efectivo' => round($devolucionesEfectivo, 2),
                'cobros_qr' => round($cobrosQr, 2),
                'devoluciones_qr' => round($devolucionesQr, 2),
                'devoluciones_total' => round($devolucionesTotal, 2),
                'monto_apertura' => round($montoApertura, 2),
                'ingresos_caja' => round($ingresosCaja, 2),
                'retiros_caja' => round($retirosCaja, 2),
                'gastos_caja' => round($gastosCaja, 2),
                'efectivo_esperado' => round($efectivoEsperado, 2),
                'cantidad_ordenes' => $cantidadOrdenes,
                'ticket_promedio' => $cantidadOrdenes > 0 ? round($ventaTotal / $cantidadOrdenes, 2) : 0,
                'cantidad_pagos_qr' => $cantidadPagosQr,
                'porcentaje_qr' => $ventaTotal > 0 ? round(($qr / $ventaTotal) * 100, 1) : 0,
                'variacion_venta' => $ventaAnterior > 0 ? round((($ventaTotal - $ventaAnterior) / $ventaAnterior) * 100, 1) : null,
            ],
            'operacion' => [
                'ordenes_pendientes' => (clone $ordenesOperativas)->count(),
                'cocina_pendientes' => $this->pendientesEstacion('COCINA', $desde, $hasta),
                'parrilla_pendientes' => $this->pendientesEstacion('PARRILLA', $desde, $hasta),
                'servicio_pendientes' => (clone $ordenesOperativas)->count(),
                'preordenes_programadas' => Orden::where('tipo_flujo', 'preorden')
                    ->where('estado_preorden', 'programada')
                    ->where(fn ($query) => $query->whereNull('estado_solicitud')->orWhere('estado_solicitud', 'aceptada'))
                    ->whereBetween('fecha_programada', [$desde, $hasta])->count(),
            ],
            'productos_por_agotar' => Producto::query()
                ->where('activo', true)->where('maneja_stock', true)
                ->whereNotNull('stock')->whereNotNull('stock_minimo')
                ->whereColumn('stock', '<=', 'stock_minimo')
                ->orderBy('stock')->orderBy('nombre')->limit(8)
                ->get(['id', 'nombre', 'stock', 'stock_minimo', 'imagen']),
            'productos_mas_vendidos' => $masVendidos->map(fn ($producto) => [
                'id' => (int) $producto->id,
                'nombre' => $producto->nombre,
                'cantidad' => (int) $producto->cantidad,
            ])->values(),
        ]);
    }

    private function pendientesEstacion(string $codigo, Carbon $desde, Carbon $hasta): int
    {
        return OrdenDetalleEstacion::query()
            ->whereIn('estado', self::ESTADOS_KDS_PENDIENTES)
            ->whereHas('estacion', fn ($query) => $query->where('codigo', $codigo)->where('activa', true))
            ->whereHas('detalle.orden', function (Builder $query) use ($desde, $hasta) {
                $query->operativas()->whereIn('estado', self::ESTADOS_OPERATIVOS);
                $this->aplicarPeriodoOperativo($query, $desde, $hasta);
            })
            ->count();
    }

    private function aplicarPeriodoOperativo(Builder $query, Carbon $desde, Carbon $hasta): void
    {
        $query->where(function (Builder $query) use ($desde, $hasta) {
            $query->where(function (Builder $query) use ($desde, $hasta) {
                $query->where('tipo_flujo', 'preorden')->where('estado_preorden', 'activada')
                    ->whereBetween('preorden_activada_en', [$desde, $hasta]);
            })->orWhere(function (Builder $query) use ($desde, $hasta) {
                $query->where(fn (Builder $query) => $query->where('tipo_flujo', 'normal')->orWhereNull('tipo_flujo'))
                    ->whereBetween('created_at', [$desde, $hasta]);
            });
        });
    }
}
