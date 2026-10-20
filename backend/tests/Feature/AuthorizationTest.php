<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The authorization matrix, read straight off the routes: every gated route carries a
 * `perm:<ability>` middleware, and for each of the six roles a route answers 403 exactly when
 * App\Support\Permissions says the role lacks that ability — and lets it through otherwise.
 * Nothing here is a hand-kept list, so a route added without a gate, or a role quietly gaining
 * an ability, fails this test.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** Route parameters that a dummy id wouldn't satisfy (whereIn constraints). */
    private const PARAMS = ['type' => 'dog_breed'];

    /** @return list<array{0:string,1:string,2:list<string>}> [method, uri, abilities] */
    private function gatedRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $perm = collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'perm:'));
            if (! $perm) {
                continue;
            }
            $uri = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => self::PARAMS[$m[1]] ?? '999999', $route->uri());
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $routes[] = [strtolower($method), '/'.$uri, explode(',', substr($perm, strlen('perm:')))];
        }

        return $routes;
    }

    public static function roles(): array
    {
        return collect(array_keys(Permissions::LABELS))->mapWithKeys(fn ($role) => [$role => [$role]])->all();
    }

    public function test_every_admin_route_is_gated_by_an_ability(): void
    {
        $ungated = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/admin/'))
            ->reject(fn ($route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'perm:')))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()->all();

        $this->assertSame([], $ungated, 'Every /api/admin route needs a perm: middleware.');
        $this->assertGreaterThan(100, count($this->gatedRoutes()));

        // Every ability a route names exists in the map (a typo would fail closed, silently).
        foreach ($this->gatedRoutes() as [$method, $uri, $abilities]) {
            foreach ($abilities as $ability) {
                $this->assertArrayHasKey($ability, Permissions::MAP, "{$method} {$uri} names an unknown ability");
            }
        }
    }

    #[DataProvider('roles')]
    public function test_each_role_reaches_exactly_the_routes_its_abilities_allow(string $role): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));
        $wrong = [];

        foreach ($this->gatedRoutes() as [$method, $uri, $abilities]) {
            $allowed = collect($abilities)->contains(fn ($ability) => Permissions::allows($role, $ability));
            $status = $this->withHeader('Accept', 'application/json')->json($method, $uri)->getStatusCode();

            if ($allowed ? in_array($status, [401, 403], true) : $status !== 403) {
                $wrong[] = sprintf('%s %s → %d (expected %s)', strtoupper($method), $uri, $status, $allowed ? 'through' : '403');
            }
        }

        $this->assertSame([], $wrong, "Role {$role} got the wrong answer on:\n".implode("\n", $wrong));
    }

    public function test_guests_get_401_on_every_gated_route(): void
    {
        foreach ($this->gatedRoutes() as [$method, $uri]) {
            $this->withHeader('Accept', 'application/json')->json($method, $uri)
                ->assertStatus(401);
        }
    }

    public function test_a_spot_check_of_who_does_what(): void
    {
        // A few readable rows of docs/ADMIN_ROLES.md, so the matrix above isn't the only proof.
        $cases = [
            ['desk_admin', 'post', '/api/donations/999999/verify', true],
            ['data_admin', 'post', '/api/donations/999999/verify', false],
            ['system_admin', 'post', '/api/donations/999999/verify', false],
            ['desk_admin', 'post', '/api/admin/categories/dog_breed', false],
            ['data_admin', 'post', '/api/admin/categories/dog_breed', true],
            ['system_admin', 'post', '/api/admin/categories/dog_breed', false],
            ['desk_admin', 'get', '/api/admin/users', false],
            ['data_admin', 'get', '/api/admin/users', false],
            ['system_admin', 'get', '/api/admin/users', true],
            ['desk_admin', 'get', '/api/admin/reports/animals', false],
            ['data_admin', 'get', '/api/admin/reports/donations', true],
            ['staff', 'get', '/api/admin/reports/animals', true],
            ['staff', 'get', '/api/admin/reports/donations', false],
            ['desk_admin', 'get', '/api/admin/animals', true],
            ['data_admin', 'get', '/api/admin/animals', false],
            ['system_admin', 'get', '/api/admin/animals', false],
            ['system_admin', 'get', '/api/admin/settings', true],
            ['data_admin', 'get', '/api/admin/settings', false],
        ];

        foreach ($cases as [$role, $method, $uri, $allowed]) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $status = $this->withHeader('Accept', 'application/json')->json($method, $uri)->getStatusCode();
            $allowed
                ? $this->assertNotContains($status, [401, 403], "{$role} was blocked on {$method} {$uri}")
                : $this->assertSame(403, $status, "{$role} got through on {$method} {$uri}");
        }
    }

    public function test_the_financial_exports_need_reports_financial(): void
    {
        // The export route is open to reports.view, so the financial block happens in resolveData().
        Sanctum::actingAs(User::factory()->staff()->create());
        $this->get('/api/admin/reports/export/csv?type=donations')->assertForbidden();
        $this->get('/api/admin/reports/export/csv?type=animals')->assertOk();

        Sanctum::actingAs(User::factory()->dataAdmin()->create());
        $this->get('/api/admin/reports/export/csv?type=donations')->assertOk();
    }

    public function test_public_endpoints_need_no_auth(): void
    {
        $this->getJson('/api/animals')->assertOk();
        $this->getJson('/api/home/settings')->assertOk();
        // A public write endpoint should fail validation (422), not auth (401).
        $this->postJson('/api/rescue-reports', [])->assertStatus(422);
    }

    public function test_volunteer_can_reach_own_volunteer_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->volunteer()->create());

        // Not a desk route — a volunteer's own record endpoint must not be forbidden.
        $this->getJson('/api/volunteer/me')->assertOk();
    }
}
