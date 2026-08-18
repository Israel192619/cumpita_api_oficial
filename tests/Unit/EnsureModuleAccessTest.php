<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureModuleAccess;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EnsureModuleAccessTest extends TestCase
{
    public function test_administrator_bypasses_every_module(): void
    {
        $response = $this->runMiddleware('Admin', 'admin');
        $this->assertSame(204, $response->getStatusCode());
        $response = $this->runMiddleware('Admin', 'servicio');
        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_operational_roles_only_enter_their_modules(): void
    {
        $this->assertSame(204, $this->runMiddleware('Cajero', 'pos')->getStatusCode());
        $this->assertSame(204, $this->runMiddleware('Cajero', 'caja')->getStatusCode());
        $this->assertSame(204, $this->runMiddleware('Cocinero', 'kds')->getStatusCode());
        $this->assertSame(204, $this->runMiddleware('Mesero', 'servicio')->getStatusCode());
        $this->assertSame(204, $this->runMiddleware('Despacho', 'despacho')->getStatusCode());
    }

    public function test_operational_role_cannot_enter_another_module(): void
    {
        $this->expectException(HttpException::class);
        $this->runMiddleware('Mesero', 'pos');
    }

    private function runMiddleware(string $roleName, string $module): Response
    {
        $role = new Role();
        $role->nombre = $roleName;
        $user = new User();
        $user->setRelation('role', $role);
        $request = Request::create('/api/test');
        $request->setUserResolver(fn () => $user);

        return (new EnsureModuleAccess())->handle($request, fn () => response('', 204), $module);
    }
}
