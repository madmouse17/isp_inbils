<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Services\Core\ApiTokenService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/**
 * Users for each mobile app, real bearer tokens, and requests that cannot inherit the previous request's login:
 * the Sanctum guard keeps its user for the life of the application instance, which in a test is every request.
 */
trait InteractsWithMobileApi
{
    protected function seedApiRoles(): void
    {
        if (! Role::query()->where('name', 'technician')->exists()) {
            $this->seed(RolePermissionSeeder::class);
            app()['cache']->forget('spatie.permission.cache');
        }
    }

    protected function apiCompany(): Company
    {
        return Company::factory()->create(['is_active' => true]);
    }

    /**
     * A customer login linked to a Customer record of $company.
     *
     * @param  array<string, mixed>  $attributes  user columns
     * @return array{0: User, 1: Customer}
     */
    protected function customerLogin(?Company $company = null, array $attributes = []): array
    {
        $this->seedApiRoles();
        $company ??= $this->apiCompany();

        $user = User::factory()->create(['company_id' => $company->id, ...$attributes]);
        $user->assignRole('customer');
        $customer = Customer::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);

        return [$user, $customer];
    }

    /**
     * A field technician: the technician role plus an active employee profile in $company.
     *
     * @param  array<string, mixed>  $attributes  user columns
     */
    protected function technicianLogin(?Company $company = null, array $attributes = []): User
    {
        $this->seedApiRoles();
        $company ??= $this->apiCompany();

        $user = User::factory()->create(['company_id' => $company->id, ...$attributes]);
        $user->assignRole('technician');
        EmployeeProfile::factory()->create(['company_id' => $company->id, 'user_id' => $user->id, 'status' => 'active']);

        return $user;
    }

    /** A real token, issued the way login issues it. Returns the bearer string. */
    protected function issueToken(User $user, string $app, string $device = 'Test phone'): string
    {
        return app(ApiTokenService::class)->issue($user, $app, $device)->plainTextToken;
    }

    /**
     * One request to /api/v1. Authenticated mutations get a fresh Idempotency-Key unless the caller sets one
     * (a null value sends none).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $headers
     */
    protected function api(string $method, string $uri, array $data = [], ?string $token = null, array $headers = []): TestResponse
    {
        app('auth')->forgetGuards();
        app('auth')->shouldUse('web');

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        if ($token !== null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true) && ! array_key_exists('Idempotency-Key', $headers)) {
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }

        return $this->json($method, '/api/v1/'.ltrim($uri, '/'), $data, array_filter($headers, fn (?string $value): bool => $value !== null));
    }
}
