<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleAccess
{
    /** @var array<string, list<string>> */
    private const ACCESS = [
        'admin' => [],
        'pos' => ['cajero', 'caja'],
        'captura-preorden' => ['cajero', 'caja', 'mesero'],
        'caja' => ['cajero', 'caja'],
        'kds' => ['cocinero', 'cocina', 'parrilla'],
        'servicio' => ['mesero', 'despacho'],
        'despacho' => ['despacho'],
    ];

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user('api');
        abort_unless($user, 401, 'No autenticado.');

        $role = $this->normalize($user->role?->nombre);
        if ($this->isAdministrator($role)) {
            return $next($request);
        }

        $allowed = self::ACCESS[$module] ?? [];
        abort_unless(in_array($role, $allowed, true), 403, 'No tienes permisos para acceder a este módulo.');

        return $next($request);
    }

    private function isAdministrator(string $role): bool
    {
        return in_array($role, ['admin', 'administrador', 'gerente'], true);
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(trim($value ?? ''));
    }
}
