<?php

namespace Tests\Feature\PublicId;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyService;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoUserSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec .scratch/opaque-ids D2: an integer id never reaches the browser. The demo company's pages are opened one by
 * one (every GET admin route that can be reached with a record of the demo data) and every prop is walked: an `id`,
 * a `*_id`, a `*_ids` list, a `*_by` or `assigned_to` holds a ULID (or a UUID, the key of a notification), or null; a
 * link never holds an integer record path.
 */
class InertiaPropsPublicIdTest extends TestCase
{
    use RefreshDatabase;

    /** Keys that end in `_id` or `_by` but are no record reference: a tax number, a gateway's own id, a column name, "type:public id". */
    private const NOT_RECORD_KEYS = ['tax_id', 'provider_message_id', 'external_id', 'transaction_id', 'order_id', 'va_number', 'composite_id', 'sort_by', 'group_by', 'order_by'];

    /** Routes that answer a file, a fragment or a page that needs a state the demo data does not hold (the SLA preview wants a category in its query). */
    private const SKIPPED = '/\.(export|pdf|download)$|\.drill|^admin\.(media\.show|components|inventory\.find|network-assets\.trace|tickets\.sla-preview)$/';

    public function test_no_page_of_the_demo_company_carries_an_integer_id(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
        $this->seed(SystemSettingSeeder::class);
        $this->seed(DemoUserSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $company = Company::query()->where('code', 'DEMO')->firstOrFail();
        $this->actingAs(User::query()->where('email', 'admin@demo.inbils.test')->firstOrFail());
        CompanyService::resetCache();

        $walked = [];
        $leaks = [];
        $failed = [];

        foreach (Route::getRoutes()->getRoutesByMethod()['GET'] as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'admin.') || preg_match(self::SKIPPED, $name) === 1) {
                continue;
            }

            $parameters = CompanyService::runAs((int) $company->id, fn (): ?array => $this->recordParameters($route));

            if ($parameters === null) {
                continue;
            }

            $response = $this->get(route($name, $parameters));

            if ($response->getStatusCode() >= 500) {
                $failed[$name] = $response->getStatusCode();

                continue;
            }

            if (! $response->isOk() || ! str_contains((string) $response->headers->get('Content-Type'), 'html')) {
                continue;
            }

            $walked[] = $name;
            array_push($leaks, ...array_map(fn (string $leak): string => $name.': '.$leak, $this->leaks($response->inertiaProps(), '')));
        }

        $this->assertSame([], $failed, 'pages that crashed');
        $this->assertGreaterThan(60, count($walked), 'too few pages were walked: '.implode(', ', $walked));
        $this->assertSame([], $leaks, "integer ids in props of:\n".implode("\n", $leaks));
    }

    /**
     * The route's parameters filled from the demo data, or null when a parameter is no record or no row exists.
     *
     * @return array<string, string>|null
     */
    private function recordParameters(RoutingRoute $route): ?array
    {
        $models = [];

        foreach ($route->signatureParameters(['subClass' => UrlRoutable::class]) as $parameter) {
            $models[$parameter->getName()] = $parameter->getType()->getName();
        }

        $values = [];

        foreach ($route->parameterNames() as $name) {
            $class = $models[$name] ?? null;
            $record = $class === null ? null : $class::query()->first();

            if ($record === null) {
                return null;
            }

            $values[$name] = $record->getRouteKey();
        }

        return $values;
    }

    /**
     * @return list<string> a description of each value that should be a public id and is not
     */
    private function leaks(mixed $value, string $path): array
    {
        if (is_string($value)) {
            return preg_match('#/(?:admin|platform)/[a-z0-9_/-]*?/\d+(?:[/?\#]|$)#i', $value) === 1 ? ["{$path} links to an integer record path: {$value}"] : [];
        }

        if (! is_array($value)) {
            return [];
        }

        $leaks = [];

        foreach ($value as $key => $item) {
            $here = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_string($key) && $this->isRecordKey($key) && ! in_array($key, self::NOT_RECORD_KEYS, true)) {
                foreach (str_ends_with($key, '_ids') && is_array($item) ? $item : [$item] as $reference) {
                    if (! $this->isPublicId($reference)) {
                        $leaks[] = "{$here} = ".json_encode($reference);
                    }
                }
            }

            array_push($leaks, ...$this->leaks($item, $here));
        }

        return $leaks;
    }

    private function isRecordKey(string $key): bool
    {
        return $key === 'id' || $key === 'assigned_to' || preg_match('/(_id|_ids|_by)$/', $key) === 1;
    }

    private function isPublicId(mixed $value): bool
    {
        // A nested structure under a `*_id` key is not a reference: its own keys are walked.
        return $value === null || is_array($value) || (is_string($value) && (Str::isUlid($value) || Str::isUuid($value)));
    }
}
