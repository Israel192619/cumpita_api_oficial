<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\GastoCaja;
use App\Models\MovimientoCaja;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\PagoOrden;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReporteController extends Controller
{
    public function ventas(Request $request)
    {
        [$data, $desde, $hasta] = $this->periodo($request, ['metodo_pago' => ['nullable', 'string', 'max:30']]);
        $query = PagoOrden::with(['orden.cliente:id,nombre', 'orden.mesa:id,numero', 'caja.user:id,name'])
            ->whereBetween('fecha_pago', [$desde, $hasta])
            ->when($data['metodo_pago'] ?? null, fn ($q, $metodo) => $q->where('metodo_pago', $metodo));
        $pagos = (clone $query)->orderByDesc('fecha_pago')->get();
        $ordenIds = $pagos->pluck('id_orden')->unique();
        $total = (float) $pagos->sum('monto_pagado');
        $ordenes = $ordenIds->count();

        return response()->json([
            'resumen' => [
                'venta_total' => round($total, 2), 'cantidad_ordenes' => $ordenes,
                'ticket_promedio' => $ordenes ? round($total / $ordenes, 2) : 0,
                'efectivo' => round((float) $pagos->where('metodo_pago', 'efectivo')->sum('monto_pagado'), 2),
                'qr' => round((float) $pagos->where('metodo_pago', 'qr')->sum('monto_pagado'), 2),
                'otros' => round((float) $pagos->whereNotIn('metodo_pago', ['efectivo', 'qr'])->sum('monto_pagado'), 2),
                'descuentos' => round((float) Orden::whereIn('id', $ordenIds)->sum('descuento'), 2),
                'devoluciones' => round(abs((float) $pagos->where('tipo_pago', 'devolucion')->sum('monto_pagado')), 2),
            ],
            'filas' => $pagos->map(fn ($pago) => [
                'id' => $pago->id, 'orden_id' => $pago->id_orden, 'fecha' => $pago->fecha_pago,
                'numero_orden' => $pago->orden?->numero_orden, 'cliente' => $pago->orden?->cliente?->nombre,
                'mesa' => $pago->orden?->mesa?->numero, 'usuario' => $pago->caja?->user?->name,
                'total' => (float) $pago->monto_pagado, 'metodo_pago' => $pago->metodo_pago,
                'tipo_pago' => $pago->tipo_pago, 'estado' => $pago->orden?->estado_pago,
            ])->values(),
            'metodos_pago' => PagoOrden::query()->distinct()->orderBy('metodo_pago')->pluck('metodo_pago')->values(),
        ]);
    }

    public function productos(Request $request)
    {
        [$data, $desde, $hasta] = $this->periodo($request, [
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
            'producto_id' => ['nullable', 'integer', 'exists:productos,id'],
        ]);
        $ordenesPagadas = PagoOrden::query()->whereBetween('fecha_pago', [$desde, $hasta])
            ->where('tipo_pago', 'pago')->select('id_orden')->distinct();
        $extras = DB::table('orden_detalle_opciones')->select('orden_detalle_id', DB::raw('SUM(precio_extra) as extras'))
            ->groupBy('orden_detalle_id');
        $filas = OrdenDetalle::query()
            ->joinSub($ordenesPagadas, 'ordenes_pagadas', fn ($join) => $join->on('orden_detalles.orden_id', '=', 'ordenes_pagadas.id_orden'))
            ->join('ordenes', 'ordenes.id', '=', 'orden_detalles.orden_id')
            ->join('productos', 'productos.id', '=', 'orden_detalles.producto_id')
            ->join('categorias', 'categorias.id', '=', 'productos.categoria_id')
            ->leftJoinSub($extras, 'extras_detalle', fn ($join) => $join->on('orden_detalles.id', '=', 'extras_detalle.orden_detalle_id'))
            ->where('ordenes.estado', '!=', 'cancelado')
            ->when($data['categoria_id'] ?? null, fn ($q, $id) => $q->where('productos.categoria_id', $id))
            ->when($data['producto_id'] ?? null, fn ($q, $id) => $q->where('productos.id', $id))
            ->groupBy('productos.id', 'productos.nombre', 'categorias.id', 'categorias.nombre')
            ->orderByDesc(DB::raw('SUM(orden_detalles.cantidad)'))
            ->get(['productos.id', 'productos.nombre', 'categorias.nombre as categoria',
                DB::raw('SUM(orden_detalles.cantidad) as cantidad'),
                DB::raw('SUM((orden_detalles.precio_unitario + COALESCE(extras_detalle.extras, 0)) * orden_detalles.cantidad) as total')])
            ->map(fn ($fila) => ['id' => (int) $fila->id, 'nombre' => $fila->nombre, 'categoria' => $fila->categoria,
                'cantidad' => (int) $fila->cantidad, 'total' => round((float) $fila->total, 2)])->values();

        return response()->json(['resumen' => [
            'productos_vendidos' => $filas->count(), 'unidades_vendidas' => $filas->sum('cantidad'),
            'total_generado' => round((float) $filas->sum('total'), 2),
        ], 'filas' => $filas]);
    }

    public function caja(Request $request)
    {
        [$data, $desde, $hasta] = $this->periodo($request, [
            'caja_id' => ['nullable', 'integer', 'exists:cajas,id'], 'usuario_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $cajas = Caja::with('user:id,name')->whereNotNull('fecha_cierre')->whereBetween('fecha_cierre', [$desde, $hasta])
            ->when($data['caja_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($data['usuario_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->orderByDesc('fecha_cierre')->get();
        $cajaIds = $cajas->pluck('id');
        $movimientos = MovimientoCaja::whereIn('caja_id', $cajaIds)->where('estado', 'ACTIVO')->get();
        $gastos = GastoCaja::whereIn('caja_id', $cajaIds)->where('estado', 'ACTIVO')->get();

        return response()->json([
            'resumen' => [
                'efectivo_esperado' => round((float) $cajas->sum('monto_esperado'), 2),
                'efectivo_contado' => round((float) $cajas->sum('monto_cierre'), 2),
                'diferencia' => round((float) $cajas->sum('diferencia'), 2),
                'qr' => round((float) PagoOrden::whereBetween('fecha_pago', [$desde, $hasta])->where('metodo_pago', 'qr')->sum('monto_pagado'), 2),
                'ingresos' => round((float) $movimientos->where('tipo', 'INGRESO')->sum('monto'), 2),
                'retiros' => round((float) $movimientos->where('tipo', 'RETIRO')->sum('monto'), 2),
                'gastos' => round((float) $gastos->sum('monto'), 2),
            ],
            'filas' => $cajas->map(fn ($caja) => [
                'id' => $caja->id, 'fecha' => $caja->fecha_cierre, 'caja' => 'Caja #'.$caja->id,
                'usuario' => $caja->user?->name, 'efectivo_esperado' => (float) $caja->monto_esperado,
                'efectivo_contado' => (float) $caja->monto_cierre, 'diferencia' => (float) $caja->diferencia,
                'estado' => $caja->estado,
            ])->values(),
            'cajas' => Caja::with('user:id,name')->whereNotNull('fecha_cierre')->latest('fecha_cierre')->limit(100)->get(['id', 'user_id', 'fecha_cierre']),
        ]);
    }

    private function periodo(Request $request, array $reglas = []): array
    {
        $data = $request->validate(array_merge([
            'desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ], $reglas));
        return [$data, Carbon::createFromFormat('Y-m-d', $data['desde'])->startOfDay(), Carbon::createFromFormat('Y-m-d', $data['hasta'])->endOfDay()];
    }
}
