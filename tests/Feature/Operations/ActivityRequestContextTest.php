<?php

namespace Tests\Feature\Operations;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Services\Core\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Platform\TenantFixtures;
use Tests\TestCase;

/**
 * Every activity row says which request wrote it (log-audit LA-AUDIT-CORE, contract C2): the request id everywhere,
 * the client address only for a row written inside a web request, the user agent only for the log names an
 * incident review reads. The company lives in its column and nowhere else.
 */
class ActivityRequestContextTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    public function test_a_row_written_in_a_request_carries_its_request_id_and_ip_and_no_json_company_copy(): void
    {
        $company = Company::factory()->create();
        Route::get('/__activity-probe', function () use ($company): string {
            AuditService::log('customer', 'probed', ['foo' => 'bar'], $company);

            return 'ok';
        });

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->get('/__activity-probe', ['X-Request-Id' => 'req-activity-1'])
            ->assertOk();

        $row = Activity::query()->where('description', 'probed')->sole();
        $this->assertSame('req-activity-1', $row->request_id);
        $this->assertSame('203.0.113.9', $row->ip_address);
        $this->assertSame($company->id, (int) $row->company_id);
        $this->assertSame(['foo' => 'bar'], $row->properties->all());
    }

    public function test_user_agent_is_kept_only_for_security_platform_and_support_rows(): void
    {
        $company = Company::factory()->create();
        Route::get('/__activity-probe', function () use ($company): string {
            foreach (['security', 'platform', 'support', 'customer'] as $log) {
                AuditService::log($log, "probe.{$log}", [], $company);
            }

            return 'ok';
        });

        $this->withHeaders(['User-Agent' => 'Probe/1.0 '.str_repeat('é', 300)])->get('/__activity-probe')->assertOk();

        $agents = Activity::query()->where('description', 'like', 'probe.%')->pluck('user_agent', 'log_name');
        foreach (['security', 'platform', 'support'] as $log) {
            $this->assertSame(255, mb_strlen((string) $agents[$log]), "{$log} keeps the agent, cut to the column");
            $this->assertStringStartsWith('Probe/1.0 é', $agents[$log]);
        }
        $this->assertNull($agents['customer'], 'a business row does not keep the agent');
    }

    public function test_a_row_written_outside_a_request_takes_the_context_request_id_and_no_ip(): void
    {
        // A queued job gets the request id of the request that dispatched it, through the Context.
        Context::add('request_id', 'job-origin-7');

        AuditService::log('security', 'probe.queued');

        $row = Activity::query()->where('description', 'probe.queued')->sole();
        $this->assertSame('job-origin-7', $row->request_id);
        $this->assertNull($row->ip_address, 'a worker or a command has no client address');
        $this->assertNull($row->user_agent);
    }

    public function test_audit_service_rows_store_the_action_as_event(): void
    {
        AuditService::log('security', 'probe.action');

        $this->assertSame('probe.action', Activity::query()->where('description', 'probe.action')->sole()->event);
    }

    public function test_rows_written_while_impersonating_name_the_impersonated_user_by_public_id(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');
        $location = fn (string $code): array => ['code' => $code, 'name' => "Region {$code}", 'type' => 'region', 'is_active' => true];

        $this->actingAs($tenant['admin'])->post(route('admin.locations.store'), $location('REG-OWN'))->assertRedirect();
        $own = Activity::query()->where('subject_type', Location::class)->where('description', 'created')->sole();
        $this->assertArrayNotHasKey('impersonated_user_id', $own->properties->all(), 'no support session, no mark');

        $this->asOperator()->post(route('platform.impersonation.start', [$tenant['root'], $tenant['admin']]), [
            'reason' => 'Komplain INV-1042',
        ])->assertRedirect(route('admin.dashboard'));
        $this->asOperator()->post(route('platform.impersonation.write'), ['confirmation_code' => $tenant['root']->code])
            ->assertStatus(303);

        $this->asTenantVisitor()->post(route('admin.locations.store'), $location('REG-SUPPORT'))->assertRedirect();

        $created = Activity::query()->where('subject_type', Location::class)->where('description', 'created')->whereKeyNot($own->getKey())->sole();
        $this->assertSame($tenant['admin']->public_id, $created->properties['impersonated_user_id']);
        $this->assertArrayNotHasKey('company_id', $created->properties->all());
    }

    public function test_the_migration_adds_nullable_columns_and_the_company_event_created_at_index(): void
    {
        $columns = collect(Schema::getColumns('activity_log'))->keyBy('name');

        foreach (['request_id' => 'varchar(128)', 'ip_address' => 'varchar(45)', 'user_agent' => 'varchar(255)'] as $name => $type) {
            $this->assertTrue($columns->has($name), "{$name} exists");
            $this->assertSame($type, $columns[$name]['type'], $name);
            $this->assertTrue($columns[$name]['nullable'], "{$name} is nullable: old rows keep NULL");
        }

        $index = collect(Schema::getIndexes('activity_log'))->firstWhere('name', 'activity_log_company_id_event_created_at_index');
        $this->assertNotNull($index);
        $this->assertSame(['company_id', 'event', 'created_at'], $index['columns']);
    }
}
