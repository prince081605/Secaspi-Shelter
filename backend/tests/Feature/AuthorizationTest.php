<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Authorization matrix: every admin-only endpoint must reject guests (401), reject
 * authenticated non-admins (403), and let admins through (not 401/403). This is the
 * regression net for the EnsureAdmin middleware + auth:sanctum gating.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** Representative set of admin-gated routes (method, uri). */
    public static function adminRoutes(): array
    {
        return [
            'list animals'        => ['get', '/api/admin/animals'],
            'list users'          => ['get', '/api/admin/users'],
            'list donations'      => ['get', '/api/admin/donations'],
            'donation stats'      => ['get', '/api/admin/donations/stats'],
            'dashboard overview'  => ['get', '/api/admin/dashboard/overview'],
            'list volunteers'     => ['get', '/api/admin/volunteers'],
            'list intakes'        => ['get', '/api/admin/intakes'],
            'adoption apps'       => ['get', '/api/admin/adoption-applications'],
            'foster apps'         => ['get', '/api/admin/foster-applications'],
            'animals report'      => ['get', '/api/admin/reports/animals'],
            'settings'            => ['get', '/api/admin/settings'],
            'rescue reports'      => ['get', '/api/rescue-reports'],
            'create animal'       => ['post', '/api/animals'],
            'create volunteer'    => ['post', '/api/admin/volunteers'],
        ];
    }

    #[DataProvider('adminRoutes')]
    public function test_guest_gets_401_on_admin_routes(string $method, string $uri): void
    {
        $this->withHeader('Accept', 'application/json')
            ->{$method}($uri)
            ->assertStatus(401);
    }

    #[DataProvider('adminRoutes')]
    public function test_non_admin_gets_403_on_admin_routes(string $method, string $uri): void
    {
        Sanctum::actingAs(User::factory()->create()); // role = user

        $this->withHeader('Accept', 'application/json')
            ->{$method}($uri)
            ->assertStatus(403);
    }

    #[DataProvider('adminRoutes')]
    public function test_admin_clears_authorization_on_admin_routes(string $method, string $uri): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->withHeader('Accept', 'application/json')->{$method}($uri);

        // Admin passes the auth/authz gate. The concrete status may be 200/422/404 depending
        // on payload/fixtures, but it must never be blocked with 401 or 403.
        $this->assertNotContains(
            $response->status(),
            [401, 403],
            "Admin was blocked on {$method} {$uri} (status {$response->status()})"
        );
    }

    public function test_public_endpoints_need_no_auth(): void
    {
        $this->getJson('/api/animals')->assertOk();
        $this->getJson('/api/home/settings')->assertOk();
        // A public write endpoint should fail validation (422), not auth (401).
        $this->postJson('/api/rescue-reports', [])->assertStatus(422);
    }
}
