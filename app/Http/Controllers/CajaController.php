<?php

namespace App\Http\Controllers;

use App\Events\CajaActualizadaEvent;
use App\Models\Caja;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CajaController extends Controller
{
    /**
     * Historial de cajas en las que el usuario puede operar.
     */
    public function index()
    {
        $cajas = $this->cajasDisponibles()
            ->with(['user:id,name,username', 'usuarios:id,name,username'])
            ->latest('fecha_apertura')
            ->get();

        return response()->json(['cajas' => $cajas]);
    }

    /**
     * Devuelve la caja física abierta a la que el usuario está autorizado.
     */
    public function actual()
    {
        $caja = $this->cajasDisponibles()
            ->with(['user:id,name,username', 'usuarios:id,name,username'])
            ->where('estado', 'abierta')
            ->latest('fecha_apertura')
            ->first();

        if (!$caja) {
            return response()->json(['caja' => null]);
        }

        $caja->setAttribute('puede_cerrar', (int) $caja->user_id === (int) auth('api')->id());
        $caja->setAttribute('es_compartida', (int) $caja->user_id !== (int) auth('api')->id());

        return response()->json([
            'caja' => $caja,
            'resumen' => $this->resumen($caja),
        ]);
    }

    /**
     * Abre una nueva jornada de caja para el cajero autenticado.
     */
    public function abrir(Request $request)
    {
        $data = $request->validate([
            'monto_apertura' => 'required|numeric|min:0',
            'observacion_apertura' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($data) {
            $usuarioId = auth('api')->id();

            $cajaAbierta = $this->cajasDisponibles()
                ->where('estado', 'abierta')
                ->lockForUpdate()
                ->first();

            if ($cajaAbierta) {
                return response()->json([
                    'message' => 'Ya tienes una caja abierta. Debes cerrarla antes de abrir otra.',
                    'caja' => $cajaAbierta,
                ], 422);
            }

            $caja = Caja::create([
                'user_id' => $usuarioId,
                'monto_apertura' => $data['monto_apertura'],
                'fecha_apertura' => now(),
                'estado' => 'abierta',
                'observacion_apertura' => $data['observacion_apertura'] ?? null,
            ]);
            $caja->usuarios()->attach($usuarioId, ['asignado_por' => $usuarioId]);
            event(new CajaActualizadaEvent($caja->id, 'abierta'));

            return response()->json([
                'message' => 'Caja abierta correctamente.',
                'caja' => $caja,
            ], 201);
        });
    }

    /**
     * Muestra una caja autorizada junto con sus cobros en efectivo.
     */
    public function show(string $id)
    {
        $caja = $this->cajaDelUsuario($id);
        $caja->setAttribute('puede_cerrar', (int) $caja->user_id === (int) auth('api')->id());

        return response()->json([
            'caja' => $caja->load(['user:id,name,username', 'usuarios:id,name,username']),
            'resumen' => $this->resumen($caja),
        ]);
    }

    /**
     * Cierra la caja. El efectivo esperado se calcula desde los pagos ya registrados.
     */
    public function cerrar(Request $request, string $id)
    {
        $data = $request->validate([
            'monto_cierre' => 'required|numeric|min:0',
            'observacion_cierre' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($id, $data) {
            $caja = Caja::where('id', $id)
                ->where('user_id', auth('api')->id())
                ->lockForUpdate()
                ->firstOrFail();

            if ($caja->estado !== 'abierta') {
                return response()->json([
                    'message' => 'Esta caja ya fue cerrada.',
                ], 422);
            }

            $resumen = $this->resumen($caja);
            $montoCierre = round((float) $data['monto_cierre'], 2);
            $esperado = $resumen['monto_esperado'];

            $caja->update([
                'monto_esperado' => $esperado,
                'monto_cierre' => $montoCierre,
                'diferencia' => round($montoCierre - $esperado, 2),
                'fecha_cierre' => now(),
                'estado' => 'cerrada',
                'observacion_cierre' => $data['observacion_cierre'] ?? null,
            ]);
            event(new CajaActualizadaEvent($caja->id, 'cerrada'));

            return response()->json([
                'message' => 'Caja cerrada correctamente.',
                'caja' => $caja->fresh(),
                'resumen' => $resumen,
            ]);
        });
    }

    /**
     * No se permite modificar una caja ya abierta o cerrada; mantiene la auditoría.
     */
    public function update(Request $request, string $id)
    {
        return response()->json(['message' => 'No está permitido modificar una caja registrada.'], 403);
    }

    public function destroy(string $id)
    {
        return response()->json(['message' => 'No está permitido eliminar una caja registrada.'], 403);
    }

    /**
     * Solo quien abrió la caja puede decidir qué otros cajeros usan su efectivo.
     */
    public function actualizarUsuarios(Request $request, string $id)
    {
        $data = $request->validate([
            'usuarios' => 'present|array',
            'usuarios.*' => 'integer|exists:users,id',
        ]);

        $caja = Caja::whereKey($id)
            ->where('user_id', auth('api')->id())
            ->where('estado', 'abierta')
            ->firstOrFail();

        $usuarios = collect($data['usuarios'])
            ->push((int) $caja->user_id)
            ->unique()
            ->values();

        if ($this->usuariosElegibles()->whereIn('users.id', $usuarios)->count() !== $usuarios->count()) {
            return response()->json([
                'message' => 'Solo puedes autorizar cajeros o administradores para esta caja.',
            ], 422);
        }

        $caja->usuarios()->syncWithPivotValues(
            $usuarios->all(),
            ['asignado_por' => auth('api')->id()]
        );
        event(new CajaActualizadaEvent($caja->id, 'autorizaciones_actualizadas'));

        return response()->json([
            'message' => 'Cajeros autorizados actualizados.',
            'caja' => $caja->fresh()->load('usuarios:id,name,username'),
        ]);
    }

    /** Lista ligera para asignar cajeros, disponible únicamente al responsable. */
    public function usuariosDisponibles(string $id)
    {
        $this->cajaDelResponsable($id);

        return response()->json([
            'usuarios' => $this->usuariosElegibles()
                ->where('users.id', '!=', auth('api')->id())
                ->get(),
        ]);
    }

    private function cajaDelUsuario(string $id): Caja
    {
        return $this->cajasDisponibles()->whereKey($id)->firstOrFail();
    }

    private function cajaDelResponsable(string $id): Caja
    {
        return Caja::whereKey($id)
            ->where('user_id', auth('api')->id())
            ->firstOrFail();
    }

    private function cajasDisponibles()
    {
        $usuarioId = auth('api')->id();

        return Caja::query()->where(function ($query) use ($usuarioId) {
            $query->where('user_id', $usuarioId)
                ->orWhereHas('usuarios', fn ($usuarios) => $usuarios->where('users.id', $usuarioId));
        });
    }

    private function usuariosElegibles()
    {
        return User::query()
            ->select('users.id', 'users.name', 'users.username')
            ->whereHas('role', function ($role) {
                $role->whereRaw('LOWER(nombre) IN (?, ?, ?, ?, ?)', [
                    'cajero', 'caja', 'admin', 'administrador', 'gerente',
                ]);
            })
            ->orderBy('users.name');
    }

    /**
     * El monto de cada pago en efectivo es el monto aplicado a la orden.
     * Las devoluciones se registran con importe negativo y reducen el efectivo esperado.
     */
    private function resumen(Caja $caja): array
    {
        $totalEfectivo = round((float) $caja->pagos()->sum('monto_pagado'), 2);
        $ingresosMovimientos = round((float) $caja->movimientos()
            ->where('estado', 'ACTIVO')->where('tipo', 'INGRESO')->sum('monto'), 2);
        $retirosMovimientos = round((float) $caja->movimientos()
            ->where('estado', 'ACTIVO')->where('tipo', 'RETIRO')->sum('monto'), 2);
        $gastosActivos = round((float) $caja->gastos()
            ->where('estado', 'ACTIVO')->sum('monto'), 2);
        $montoApertura = round((float) $caja->monto_apertura, 2);

        return [
            'monto_apertura' => $montoApertura,
            'ingresos_efectivo' => $totalEfectivo,
            'ingresos_movimientos' => $ingresosMovimientos,
            'retiros_movimientos' => $retirosMovimientos,
            'gastos_activos' => $gastosActivos,
            'monto_esperado' => round($montoApertura + $totalEfectivo + $ingresosMovimientos - $retirosMovimientos - $gastosActivos, 2),
            'cantidad_pagos_efectivo' => $caja->pagos()->count(),
        ];
    }
}
