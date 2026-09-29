<?php

namespace App\Services;

use App\Models\HistorialCambioOrden;
use App\Models\Orden;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ServicioAsignacionAutomaticaService
{
    private const REGISTRO_SESIONES = 'servicio:asignacion:sesiones';
    private const BLOQUEO = 'servicio:asignacion:bloqueo';
    private const SEGUNDOS_ACTIVO = 90;
    private const SEGUNDOS_PAUSA_LIBERACION = 60;
    private const SEGUNDOS_OCUPADO = 600;
    private const MAX_FICHAS_AUTOMATICAS = 2;

    public function registrarYAsignar(User $mesero, string $sessionId): Collection
    {
        return Cache::lock(self::BLOQUEO, 10)->block(5, function () use ($mesero, $sessionId) {
            $this->registrarSesion($mesero->id, $sessionId);

            return $this->asignarDisponibles(now()->toDateString());
        });
    }

    public function desactivarSesion(string $sessionId): void
    {
        Cache::lock(self::BLOQUEO, 10)->block(5, function () use ($sessionId) {
            $sesiones = $this->sesionesVigentes();
            unset($sesiones[$sessionId]);
            Cache::put(self::REGISTRO_SESIONES, $sesiones, now()->addDay());
        });
    }

    public function establecerDisponibilidad(User $mesero, string $sessionId, bool $disponible): array
    {
        return Cache::lock(self::BLOQUEO, 10)->block(5, function () use ($mesero, $sessionId, $disponible) {
            $this->registrarSesion($mesero->id, $sessionId);
            if ($disponible) {
                Cache::forget($this->claveOcupado($mesero->id));
            } else {
                $hasta = now()->addSeconds(self::SEGUNDOS_OCUPADO);
                Cache::put($this->claveOcupado($mesero->id), $hasta->timestamp, $hasta);
            }

            return [
                ...$this->estadoDisponibilidad($mesero->id),
                'asignadas' => $disponible ? $this->asignarDisponibles(now()->toDateString()) : collect(),
            ];
        });
    }

    public function estadoDisponibilidad(int $meseroId): array
    {
        $hasta = (int) Cache::get($this->claveOcupado($meseroId), 0);
        if ($hasta <= now()->timestamp) {
            if ($hasta) Cache::forget($this->claveOcupado($meseroId));
            return ['disponible' => true, 'ocupado_hasta' => null];
        }

        return [
            'disponible' => false,
            'ocupado_hasta' => now()->setTimestamp($hasta)->toIso8601String(),
        ];
    }

    public function pausarMesero(int $meseroId): void
    {
        Cache::put($this->clavePausa($meseroId), true, now()->addSeconds(self::SEGUNDOS_PAUSA_LIBERACION));
    }

    public function reasignarDisponibles(): Collection
    {
        return Cache::lock(self::BLOQUEO, 10)->block(5, fn () =>
            $this->asignarDisponibles(now()->toDateString())
        );
    }

    private function registrarSesion(int $meseroId, string $sessionId): void
    {
        $sesiones = $this->sesionesVigentes();
        $sesiones[$sessionId] = ['user_id' => $meseroId, 'visto_en' => now()->timestamp];
        Cache::put(self::REGISTRO_SESIONES, $sesiones, now()->addDay());
    }

    private function sesionesVigentes(): array
    {
        $limite = now()->subSeconds(self::SEGUNDOS_ACTIVO)->timestamp;

        return collect(Cache::get(self::REGISTRO_SESIONES, []))
            ->filter(fn ($sesion) => is_array($sesion)
                && isset($sesion['user_id'], $sesion['visto_en'])
                && (int) $sesion['visto_en'] >= $limite)
            ->all();
    }

    private function asignarDisponibles(string $fecha): Collection
    {
        $idsActivos = collect($this->sesionesVigentes())
            ->pluck('user_id')->map(fn ($id) => (int) $id)->unique()
            ->reject(fn ($id) => Cache::has($this->clavePausa($id)) || !$this->estadoDisponibilidad($id)['disponible'])
            ->values();
        if ($idsActivos->isEmpty()) return collect();

        $meseros = User::query()
            ->whereIn('id', $idsActivos)
            ->whereHas('role', fn ($query) => $query->whereRaw('LOWER(nombre) = ?', ['mesero']))
            ->orderBy('id')->get(['id']);
        if ($meseros->isEmpty()) return collect();

        return DB::transaction(function () use ($meseros, $fecha) {
            DB::table('users')->whereIn('id', $meseros->pluck('id'))->orderBy('id')->lockForUpdate()->get(['id']);

            $cargas = Orden::query()->operativas()->deFechaOperativa($fecha)
                ->whereIn('mesero_id', $meseros->pluck('id'))
                ->whereNotIn('estado', ['entregado', 'cancelado'])
                ->selectRaw('mesero_id, COUNT(*) as cantidad')
                ->groupBy('mesero_id')->pluck('cantidad', 'mesero_id')
                ->map(fn ($cantidad) => (int) $cantidad)->all();
            foreach ($meseros as $mesero) $cargas[$mesero->id] ??= 0;

            $pendientes = Orden::query()
                ->with('detalles.historialCambios.user:id,name')
                ->operativas()->deFechaOperativa($fecha)
                ->whereNull('mesero_id')
                ->whereNotIn('estado', ['entregado', 'cancelado'])
                ->orderByRaw("CASE WHEN tipo_flujo = 'preorden' AND estado_preorden = 'activada' THEN 0 ELSE 1 END")
                ->orderBy('created_at')->orderBy('id')
                ->lockForUpdate()->get();

            $asignadas = collect();
            $colaboracion = app(ServicioColaboracionService::class);
            foreach ($pendientes as $orden) {
                if ($orden->detalles->contains(fn ($detalle) => $colaboracion->estado($detalle)['es_apoyo'])) continue;

                $mesero = $meseros
                    ->filter(fn ($item) => $cargas[$item->id] < self::MAX_FICHAS_AUTOMATICAS)
                    ->sortBy(fn ($item) => sprintf('%05d-%010d', $cargas[$item->id], $item->id))
                    ->first();
                if (!$mesero) break;

                $orden->update(['mesero_id' => $mesero->id, 'tomada_en' => now()]);
                HistorialCambioOrden::create([
                    'orden_id' => $orden->id,
                    'user_id' => $mesero->id,
                    'tipo_cambio' => 'estado_cambiado',
                    'datos_anterior' => ['mesero_id' => null, 'tomada_en' => null],
                    'datos_nuevo' => [
                        'mesero_id' => $mesero->id,
                        'tomada_en' => $orden->tomada_en,
                        'accion' => 'asignacion_automatica',
                    ],
                ]);
                $cargas[$mesero->id]++;
                $asignadas->push($orden);
            }

            return $asignadas;
        });
    }

    private function clavePausa(int $meseroId): string
    {
        return 'servicio:asignacion:pausa:' . $meseroId;
    }

    private function claveOcupado(int $meseroId): string
    {
        return 'servicio:asignacion:ocupado:' . $meseroId;
    }
}
