<?php

namespace App\Http\Controllers;

use App\Services\PuestoCocinaService;
use App\Models\Orden;
use App\Models\PuestoEstacion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class PuestoEstacionController extends Controller
{
    public function index()
    {
        $cocina = $this->getCocinaEstacion();

        $puestos = PuestoEstacion::with(['usuario', 'orden'])
            ->where('estacion_id', $cocina->id)
            ->orderBy('nombre')
            ->get();

        return response()->json(['puestos' => $puestos]);
    }

    public function control()
    {
        $user = $this->getAuthenticatedCocinero();

        $puestos = PuestoEstacion::with(['usuario', 'orden.cliente', 'orden.mesa', 'orden.detalles.producto.categoria', 'orden.detalles.estacion'])
            ->where('estacion_id', $user->estacion_id)
            ->orderBy('nombre')
            ->get();

        $puestoActual = app(PuestoCocinaService::class)->obtenerControlPuesto($user->id);
        $ordenesDisponibles = app(PuestoCocinaService::class)->obtenerOrdenesDisponibles($user->estacion_id);

        return response()->json([
            'usuario' => $user,
            'puesto' => $puestoActual,
            'estado' => $puestoActual?->orden_estado_cocina ?? 'sin_orden',
            'puestos' => $puestos,
            'ordenes_disponibles' => $ordenesDisponibles,
        ]);
    }

    public function ocupar(PuestoEstacion $puesto)
    {
        $user = $this->getAuthenticatedCocinero();
        $this->ensurePuestoBelongsToCocina($puesto);

        $puestoActualizado = app(PuestoCocinaService::class)->ocuparPuesto($puesto, $user->id);

        return response()->json(['puesto' => $puestoActualizado]);
    }

    public function asignarOrden(PuestoEstacion $puesto, Request $request)
    {
        $user = $this->getAuthenticatedCocinero();
        $this->ensurePuestoBelongsToCocina($puesto);

        $data = $request->validate([
            'orden_id' => 'required|integer|exists:ordenes,id',
        ]);

        $orden = Orden::where('id', $data['orden_id'])
            ->operativas()
            ->with('detalles')
            ->firstOrFail();

        if (!$orden->detalles->contains(fn ($detalle) => $detalle->estacion_id === $user->estacion_id)) {
            abort(Response::HTTP_FORBIDDEN, 'La orden no pertenece a la estación Cocina.');
        }

        $puestoActualizado = app(PuestoCocinaService::class)->cambiarPedido($puesto, $orden);

        return response()->json(['puesto' => $puestoActualizado]);
    }

    public function liberarOrden(PuestoEstacion $puesto)
    {
        $this->getAuthenticatedCocinero();
        $this->ensurePuestoBelongsToCocina($puesto);

        $puestoActualizado = app(PuestoCocinaService::class)->liberarOrden($puesto);

        return response()->json(['puesto' => $puestoActualizado]);
    }

    public function ordenarLista(PuestoEstacion $puesto)
    {
        $this->getAuthenticatedCocinero();
        $this->ensurePuestoBelongsToCocina($puesto);

        $resultado = app(PuestoCocinaService::class)->marcarLista($puesto);

        return response()->json([
            'puesto' => $resultado['puesto'],
            'orden' => $resultado['orden'],
        ]);
    }

    public function liberar(PuestoEstacion $puesto)
    {
        $this->getAuthenticatedCocinero();
        $this->ensurePuestoBelongsToCocina($puesto);

        $puestoActualizado = app(PuestoCocinaService::class)->liberarPuesto($puesto);

        return response()->json(['puesto' => $puestoActualizado]);
    }

    private function getAuthenticatedCocinero()
    {
        /** @var User $user */
        $user = Auth::user();

        if (!$user) {
            abort(401, 'Usuario no autenticado.');
        }

        $user->loadMissing(['role', 'estacion']);

        $rol = mb_strtolower($user->role?->nombre ?? '');
        if (in_array($rol, ['admin', 'administrador', 'gerente'], true)) {
            return $user;
        }

        if ($user->estacion?->codigo !== 'COCINA') {
            abort(403, 'El usuario no pertenece a la estación Cocina.');
        }

        if ($user->role?->nombre !== 'Cocinero') {
            abort(403, 'El usuario no está autorizado.');
        }

        return $user;
    }

    private function getCocinaEstacion()
    {
        return \App\Models\EstacionTrabajo::where('codigo', 'COCINA')->firstOrFail();
    }

    private function ensurePuestoBelongsToCocina(PuestoEstacion $puesto): void
    {
        $puesto->loadMissing('estacion');

        if ($puesto->estacion?->codigo !== 'COCINA') {
            abort(Response::HTTP_FORBIDDEN, 'El puesto no pertenece a la estación Cocina.');
        }
    }
}
