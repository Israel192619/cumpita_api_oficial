<?php

namespace App\Services;

use App\Models\KdsAsignacion;
use App\Models\KdsSesion;
use App\Models\Orden;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KdsAsignacionService
{
    private const COLORES = ['amarillo', 'indigo', 'salmon', 'verde'];

    /** @return array{sesion:KdsSesion,cola_cambio:bool} */
    public function registrarSesion(User $usuario, int $estacionId): array
    {
        return DB::transaction(function () use ($usuario, $estacionId) {
            $asignacionesAntes = $this->huellaAsignaciones($estacionId);
            $this->limpiarSesionesInactivas($estacionId);
            $sesion = KdsSesion::where('user_id', $usuario->id)->where('estacion_id', $estacionId)->lockForUpdate()->first();

            if (!$sesion) {
                $usados = KdsSesion::where('estacion_id', $estacionId)->orderBy('id')->pluck('color')->all();
                $color = collect(self::COLORES)->first(fn ($color) => !in_array($color, $usados, true))
                    ?? self::COLORES[count($usados) % count(self::COLORES)];
                $sesion = KdsSesion::create([
                    'user_id' => $usuario->id,
                    'estacion_id' => $estacionId,
                    'color' => $color,
                    'ultima_actividad' => now(),
                ]);
            } else {
                $sesion->update(['ultima_actividad' => now()]);
            }

            $this->sincronizarAsignaciones($estacionId);
            return [
                'sesion' => $sesion->fresh(),
                'cola_cambio' => $asignacionesAntes !== $this->huellaAsignaciones($estacionId),
            ];
        });
    }

    /** @return array<int, string> */
    private function huellaAsignaciones(int $estacionId): array
    {
        return KdsAsignacion::where('estacion_id', $estacionId)->orderBy('user_id')->orderBy('orden_id')
            ->get(['user_id', 'orden_id'])
            ->map(fn (KdsAsignacion $asignacion) => "{$asignacion->user_id}:{$asignacion->orden_id}")
            ->all();
    }

    public function sincronizarAsignaciones(int $estacionId): void
    {
        $this->limpiarSesionesInactivas($estacionId);
        $sesiones = KdsSesion::where('estacion_id', $estacionId)
            ->where('ultima_actividad', '>=', now()->subSeconds(90))
            ->orderBy('created_at')->get();

        $asignaciones = KdsAsignacion::where('estacion_id', $estacionId)->lockForUpdate()->get();
        foreach ($asignaciones as $asignacion) {
            if (!$sesiones->contains('user_id', $asignacion->user_id) || !$this->ordenTieneTrabajoActivo($asignacion->orden_id, $estacionId)) {
                $asignacion->delete();
            }
        }

        $ordenes = $this->colaDisponible($estacionId);
        $ocupadas = KdsAsignacion::where('estacion_id', $estacionId)->pluck('orden_id')->all();
        foreach ($sesiones as $sesion) {
            $tieneFicha = KdsAsignacion::where('estacion_id', $estacionId)->where('user_id', $sesion->user_id)->exists();
            if ($tieneFicha) continue;
            $ordenId = $ordenes->first(fn (Orden $orden) => !in_array($orden->id, $ocupadas, true))?->id;
            if (!$ordenId) continue;
            KdsAsignacion::create([
                'orden_id' => $ordenId,
                'estacion_id' => $estacionId,
                'user_id' => $sesion->user_id,
                'asignada_en' => now(),
            ]);
            $ocupadas[] = $ordenId;
        }
    }

    /** @return array<int, array{user_id:int,nombre:string,color:string}> */
    public function asignacionesParaEstacion(int $estacionId): array
    {
        return KdsAsignacion::with(['usuario:id,name'])
            ->where('estacion_id', $estacionId)
            ->get()
            ->mapWithKeys(function (KdsAsignacion $asignacion) use ($estacionId) {
                $color = KdsSesion::where('user_id', $asignacion->user_id)->where('estacion_id', $estacionId)->value('color') ?? 'amarillo';
                return [$asignacion->orden_id => [
                    'user_id' => $asignacion->user_id,
                    'nombre' => $asignacion->usuario?->name ?? 'Cocinero',
                    'color' => $color,
                ]];
            })->all();
    }

    private function limpiarSesionesInactivas(int $estacionId): void
    {
        $ids = KdsSesion::where('estacion_id', $estacionId)
            ->where('ultima_actividad', '<', now()->subSeconds(90))->pluck('user_id');
        if ($ids->isEmpty()) return;
        KdsAsignacion::where('estacion_id', $estacionId)->whereIn('user_id', $ids)->delete();
        KdsSesion::where('estacion_id', $estacionId)->whereIn('user_id', $ids)->delete();
    }

    private function colaDisponible(int $estacionId): Collection
    {
        $ordenes = Orden::with(['detalles.estadosEstacion'])
            ->operativas()->deFechaOperativa(now()->toDateString())
            ->whereHas('detalles.estadosEstacion', fn ($query) => $query
                ->where('estacion_id', $estacionId)
                ->whereIn('estado', ['pendiente', 'en_preparacion', 'listo_para_recoger']))
            ->orderBy('created_at')->get()
            ->filter(fn (Orden $orden) => $this->ordenEsTrabajable($orden, $estacionId))
            ->values();

        // La asignación debe respetar la misma señal que ve Cocina: un producto
        // liberado por su estación previa tiene prioridad sobre una ficha normal
        // que todavía no requiere atención inmediata.
        $pases = $ordenes->filter(fn (Orden $orden) => $this->ordenTienePaseListo($orden, $estacionId));
        $normales = $ordenes->reject(fn (Orden $orden) => $this->ordenTienePaseListo($orden, $estacionId));

        return $pases->concat($normales)->values();
    }

    private function ordenTieneTrabajoActivo(int $ordenId, int $estacionId): bool
    {
        $orden = Orden::with(['detalles.estadosEstacion'])->find($ordenId);

        // Un estado pendiente por sí solo no basta: si depende de Parrilla y
        // sigue bloqueado, Cocina no puede hacer nada todavía. En ese caso la
        // ficha se libera para asignar la siguiente que sí sea atendible.
        return $orden !== null && $this->ordenEsTrabajable($orden, $estacionId);
    }

    private function ordenEsTrabajable(Orden $orden, int $estacionId): bool
    {
        $hayTrabajoDisponible = false;

        foreach ($orden->detalles as $detalle) {
            $estadoActual = $detalle->estadosEstacion->firstWhere('estacion_id', $estacionId);
            if (!$estadoActual || !in_array($estadoActual->estado, ['pendiente', 'en_preparacion', 'listo_para_recoger'], true)) continue;

            if ((int) $detalle->estacion_id === $estacionId) {
                $hayTrabajoDisponible = true;
                continue;
            }

            $principal = $detalle->estadosEstacion->firstWhere('estacion_id', (int) $detalle->estacion_id);
            if ($principal && in_array($principal->estado, ['listo_para_recoger', 'recogido', 'servido'], true)) {
                $hayTrabajoDisponible = true;
                continue;
            }

            // Hay un producto pendiente que todavía depende de otra estación.
            // No asignamos esta ficha para no invitar a servir el pollo, sopa u
            // otro componente antes de poder entregar el pedido completo.
            return false;
        }

        return $hayTrabajoDisponible;
    }

    private function ordenTienePaseListo(Orden $orden, int $estacionId): bool
    {
        foreach ($orden->detalles as $detalle) {
            if ((int) $detalle->estacion_id === $estacionId) continue;

            $estadoActual = $detalle->estadosEstacion->firstWhere('estacion_id', $estacionId);
            if (!$estadoActual || !in_array($estadoActual->estado, ['pendiente', 'en_preparacion', 'listo_para_recoger'], true)) continue;

            $principal = $detalle->estadosEstacion->firstWhere('estacion_id', (int) $detalle->estacion_id);
            if ($principal && in_array($principal->estado, ['listo_para_recoger', 'recogido', 'servido'], true)) {
                return true;
            }
        }

        return false;
    }

}
