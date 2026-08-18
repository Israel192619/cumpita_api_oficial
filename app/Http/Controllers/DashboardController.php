<?php

namespace App\Http\Controllers;

use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\OrdenDetalleEstacion;
use App\Models\PagoOrden;
use App\Models\Producto;
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
        ]);
        $desde = Carbon::createFromFormat('Y-m-d', $data['desde'])->startOfDay();
        $hasta = Carbon::createFromFormat('Y-m-d', $data['hasta'])->endOfDay();

        $pagos = PagoOrden::query()->whereBetween('fecha_pago', [$desde, $hasta]);
        $ventaTotal = (float) (clone $pagos)->sum('monto_pagado');
        $qr = (float) (clone $pagos)->where('metodo_pago', 'qr')->sum('monto_pagado');
        $efectivo = (float) (clone $pagos)->where('metodo_pago', 'efectivo')->sum('monto_pagado');
        $cantidadOrdenes = (clone $pagos)->where('tipo_pago', 'pago')->distinct()->count('id_orden');

        $ordenesPeriodo = Orden::query();
        $this->aplicarPeriodoOperativo($ordenesPeriodo, $desde, $hasta);
        $ordenesPeriodo->where('estado', '!=', 'cancelado')
            ->where(fn (Builder $query) => $query->where('tipo_flujo', '!=', 'preorden')
                ->orWhereNull('tipo_flujo')->orWhere('estado_preorden', 'activada'));

        $masVendidos = OrdenDetalle::query()
            ->joinSub($ordenesPeriodo->select('ordenes.id'), 'ordenes_periodo', fn ($join) =>
                $join->on('orden_detalles.orden_id', '=', 'ordenes_periodo.id'))
            ->join('productos', 'productos.id', '=', 'orden_detalles.producto_id')
            ->groupBy('productos.id', 'productos.nombre')
            ->orderByDesc(DB::raw('SUM(orden_detalles.cantidad)'))
            ->limit(5)
            ->get([
                'productos.id', 'productos.nombre',
                DB::raw('SUM(orden_detalles.cantidad) as cantidad'),
            ]);

        $ordenesOperativas = Orden::query()->operativas()
            ->whereIn('estado', self::ESTADOS_OPERATIVOS);

        return response()->json([
            'periodo' => ['desde' => $data['desde'], 'hasta' => $data['hasta']],
            'kpis' => [
                'venta_total' => round($ventaTotal, 2),
                'qr' => round($qr, 2),
                'efectivo' => round($efectivo, 2),
                'cantidad_ordenes' => $cantidadOrdenes,
                'ticket_promedio' => $cantidadOrdenes > 0 ? round($ventaTotal / $cantidadOrdenes, 2) : 0,
            ],
            'operacion' => [
                'ordenes_pendientes' => (clone $ordenesOperativas)->count(),
                'cocina_pendientes' => $this->pendientesEstacion('COCINA'),
                'parrilla_pendientes' => $this->pendientesEstacion('PARRILLA'),
                'servicio_pendientes' => (clone $ordenesOperativas)->count(),
                'preordenes_programadas' => Orden::where('tipo_flujo', 'preorden')
                    ->where('estado_preorden', 'programada')->count(),
            ],
            'productos_por_agotar' => Producto::query()
                ->where('activo', true)->where('maneja_stock', true)
                ->whereNotNull('stock')->whereNotNull('stock_minimo')
                ->whereColumn('stock', '<=', 'stock_minimo')
                ->orderBy('stock')->orderBy('nombre')->limit(8)
                ->get(['id', 'nombre', 'stock', 'stock_minimo']),
            'productos_mas_vendidos' => $masVendidos->map(fn ($producto) => [
                'id' => (int) $producto->id,
                'nombre' => $producto->nombre,
                'cantidad' => (int) $producto->cantidad,
            ])->values(),
        ]);
    }

    private function pendientesEstacion(string $codigo): int
    {
        return OrdenDetalleEstacion::query()
            ->whereIn('estado', self::ESTADOS_KDS_PENDIENTES)
            ->whereHas('estacion', fn ($query) => $query->where('codigo', $codigo)->where('activa', true))
            ->whereHas('detalle.orden', fn ($query) => $query->operativas()->whereIn('estado', self::ESTADOS_OPERATIVOS))
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
