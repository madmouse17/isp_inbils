<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Reporting\Http\Resources\AuditLogResource;
use Modules\Reporting\Queries\AuditLogQuery;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuditLogTenantIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private Company $companyA;

    private Company $companyB;

    private User $adminA;

    private User $adminB;

    private User $staffA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->companyA = Company::factory()->create(['name' => 'Tenant Alpha', 'is_active' => true]);
        $this->companyB = Company::factory()->create(['name' => 'Tenant Beta', 'is_active' => true]);

        $this->adminA = User::factory()->create([
            'company_id' => $this->companyA->id,
            'email_verified_at' => now(),
            'is_active' => true,
            'name' => 'Admin Alpha',
            'email' => 'admin.alpha@example.test',
        ]);
        $this->adminA->assignRole('admin');

        $this->adminB = User::factory()->create([
            'company_id' => $this->companyB->id,
            'email_verified_at' => now(),
            'is_active' => true,
            'name' => 'Admin Beta',
            'email' => 'admin.beta@example.test',
        ]);
        $this->adminB->assignRole('admin');

        $this->staffA = User::factory()->create([
            'company_id' => $this->companyA->id,
            'email_verified_at' => now(),
            'is_active' => true,
            'name' => 'Staff Alpha',
            'email' => 'staff.alpha@example.test',
        ]);
        $this->staffA->assignRole('staff');

        CompanyService::resetCache();
        CompanyService::useCompanyForTests((int) $this->companyA->id);
    }

    public function test_audit_service_captures_company_id_server_side(): void
    {
        $this->actingAs($this->adminA);
        CompanyService::useCompanyForTests((int) $this->companyA->id);

        $customerA = Customer::factory()->create(['company_id' => $this->companyA->id]);

        $activity = AuditService::log(
            module: 'customer',
            action: 'created_test',
            properties: ['foo' => 'bar'],
            subject: $customerA
        );

        $this->assertNotNull($activity);
        $this->assertSame((int) $this->companyA->id, (int) $activity->company_id);
        $this->assertSame((int) $this->companyA->id, (int) $activity->properties['company_id']);

        // Test model lifecycle activity logging
        $modelActivity = AuditService::logModelActivity($customerA, 'updated_test');
        $this->assertNotNull($modelActivity);
        $this->assertSame((int) $this->companyA->id, (int) $modelActivity->company_id);

        // Client cannot spoof company_id away from server-authoritative subject/context
        $spoofed = AuditService::log(
            module: 'customer',
            action: 'spoof_attempt',
            properties: ['company_id' => $this->companyB->id, 'danger' => 'payload'],
            subject: $customerA
        );
        $this->assertNotNull($spoofed);
        $this->assertSame((int) $this->companyA->id, (int) $spoofed->company_id);
        $this->assertSame((int) $this->companyA->id, (int) $spoofed->properties['company_id']);
    }

    public function test_two_tenant_collision_isolation_in_audit_log_query(): void
    {
        $this->actingAs($this->adminA);
        CompanyService::useCompanyForTests((int) $this->companyA->id);

        // Seed logs for Tenant A
        AuditService::log('billing', 'invoice_generated_a1', ['amount' => 100000]);
        AuditService::log('billing', 'invoice_generated_a2', ['amount' => 200000]);

        // Seed logs for Tenant B
        $this->actingAs($this->adminB);
        CompanyService::useCompanyForTests((int) $this->companyB->id);
        AuditService::log('billing', 'invoice_generated_b1', ['amount' => 500000]);
        AuditService::log('billing', 'invoice_generated_b2', ['amount' => 900000]);

        // Query as Tenant A: strictly Company A rows returned
        $this->actingAs($this->adminA);
        CompanyService::useCompanyForTests((int) $this->companyA->id);

        $resultsA = AuditLogQuery::execute();
        $this->assertNotEmpty($resultsA);
        foreach ($resultsA as $row) {
            $this->assertSame((int) $this->companyA->id, (int) $row['company_id']);
            $this->assertNotSame('invoice_generated_b1', $row['description']);
            $this->assertNotSame('invoice_generated_b2', $row['description']);
        }

        $countA = AuditLogQuery::query()->count();
        $this->assertGreaterThanOrEqual(2, $countA);

        // Paginated query for Tenant A
        $paginatedA = AuditLogQuery::paginate(perPage: 10);
        foreach ($paginatedA->items() as $item) {
            $this->assertSame((int) $this->companyA->id, (int) $item->company_id);
        }

        // Query as Tenant B: strictly Company B rows returned
        $this->actingAs($this->adminB);
        CompanyService::useCompanyForTests((int) $this->companyB->id);

        $resultsB = AuditLogQuery::execute();
        $this->assertNotEmpty($resultsB);
        foreach ($resultsB as $row) {
            $this->assertSame((int) $this->companyB->id, (int) $row['company_id']);
            $this->assertNotSame('invoice_generated_a1', $row['description']);
            $this->assertNotSame('invoice_generated_a2', $row['description']);
        }
    }

    public function test_direct_url_and_filter_foreign_tenant_fails_closed(): void
    {
        $this->actingAs($this->adminB);
        CompanyService::useCompanyForTests((int) $this->companyB->id);
        AuditService::log('secret', 'classified_b_action', ['secret' => 'beta_data']);

        // Switch to Tenant A
        $this->actingAs($this->adminA);
        CompanyService::useCompanyForTests((int) $this->companyA->id);

        // 1. Filtering by foreign user_id fails closed (returns 0 rows)
        $foreignFiltered = AuditLogQuery::execute(userId: $this->adminB->id);
        $this->assertEmpty($foreignFiltered);

        // 2. Supplying foreign companyId explicitly throws 403
        $this->expectException(HttpException::class);
        AuditLogQuery::query(companyId: $this->companyB->id);
    }

    public function test_foreign_user_filter_stays_empty_in_page_and_paginated_query(): void
    {
        $this->actingAs($this->adminB);
        CompanyService::useCompanyForTests((int) $this->companyB->id);
        AuditService::log('security', 'foreign_actor_event');

        $this->actingAs($this->adminA);
        CompanyService::useCompanyForTests((int) $this->companyA->id);
        AuditService::log('security', 'company_actor_event');

        $this->get(route('admin.reports.audit-log', ['user_id' => $this->adminB->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.user_id', (string) $this->adminB->id)
                ->has('data.data', 0)
            );

        $this->assertSame(0, AuditLogQuery::paginate(userId: (int) $this->adminB->id)->total());
    }

    public function test_report_page_and_export_never_list_another_companys_activity(): void
    {
        $this->actingAs($this->adminB);
        CompanyService::useCompanyForTests((int) $this->companyB->id);
        AuditService::log('billing', 'beta_only_event');

        $this->actingAs($this->adminA);
        CompanyService::useCompanyForTests((int) $this->companyA->id);
        AuditService::log('billing', 'alpha_only_event');
        $this->assertDatabaseHas('activity_log', ['description' => 'beta_only_event', 'company_id' => $this->companyB->id]);

        $descriptions = fn (array $query): array => array_column(
            $this->get(route('admin.reports.audit-log', $query))->assertOk()->inertiaProps('data.data'),
            'description',
        );
        $export = fn (array $query): string => $this->get(route('admin.reports.audit-log.export', $query))->assertOk()->streamedContent();

        $this->assertContains('alpha_only_event', $descriptions([]));
        $this->assertNotContains('beta_only_event', $descriptions([]));
        $this->assertStringContainsString('alpha_only_event', $export([]));
        $this->assertStringNotContainsString('beta_only_event', $export([]));

        // The other company's actor by id, and the other company's actor and event in the free-text search.
        foreach ([
            ['user_id' => $this->adminB->id],
            ['user_id' => $this->adminB->id, 'log_name' => 'billing'],
            ['search' => 'Admin Beta'],
            ['search' => 'admin.beta@example.test'],
            ['search' => 'beta_only_event'],
        ] as $filters) {
            $this->assertSame([], $descriptions($filters), json_encode($filters));
            $this->assertStringNotContainsString('beta_only_event', $export($filters), json_encode($filters));
        }
    }

    public function test_unauthenticated_and_no_company_access_denied(): void
    {
        // Unauthenticated access redirects or denies
        $response = $this->get(route('admin.reports.audit-log'));
        $this->assertTrue($response->isRedirect() || $response->isClientError());

        // Authenticated user with no company_id is denied access
        $noCompanyUser = User::factory()->create([
            'company_id' => null,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $noCompanyUser->assignRole('admin');

        $this->actingAs($noCompanyUser);
        $response = $this->get(route('admin.reports.audit-log'));
        $this->assertTrue($response->isRedirect(url('/setup')) || $response->isForbidden());

        // Direct query without company context strictly aborts 403 fail-closed
        CompanyService::resetCache();
        CompanyService::useCompanyForTests(null);
        try {
            AuditLogQuery::query();
            $this->fail('Expected 403 HttpException when querying without company context');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('Company context is required.', $e->getMessage());
        }
    }

    public function test_permission_denial_for_actors_without_report_audit_view(): void
    {
        // Staff has report.view but NOT report.audit.view
        $this->actingAs($this->staffA);
        CompanyService::useCompanyForTests((int) $this->companyA->id);

        $response = $this->get(route('admin.reports.audit-log'));
        $response->assertForbidden();

        // Admin has report.audit.view -> 200 OK
        $this->actingAs($this->adminA);
        $response = $this->get(route('admin.reports.audit-log'));
        $response->assertOk();
    }

    public function test_audit_properties_not_sent_without_capability(): void
    {
        $this->actingAs($this->adminA);
        CompanyService::useCompanyForTests((int) $this->companyA->id);

        $activity = AuditService::log('sensitive', 'action_with_secret', [
            'api_token' => 'confidential_123',
            'amount' => 50000,
        ]);
        $this->assertNotNull($activity);

        // 1. AuditLogResource with staff user (lacks report.audit.view)
        $requestStaff = Request::create('/admin/reports/audit-log', 'GET');
        $requestStaff->setUserResolver(fn () => $this->staffA);

        $resourceStaff = (new AuditLogResource($activity))->toArray($requestStaff);
        $this->assertEmpty($resourceStaff['properties'], 'Properties must be empty for actor lacking capability');

        // 2. AuditLogResource with admin user (has report.audit.view)
        $requestAdmin = Request::create('/admin/reports/audit-log', 'GET');
        $requestAdmin->setUserResolver(fn () => $this->adminA);

        $resourceAdmin = (new AuditLogResource($activity))->toArray($requestAdmin);
        $this->assertNotEmpty($resourceAdmin['properties']);
        $this->assertSame('confidential_123', $resourceAdmin['properties']['api_token']);

        // 3. AuditLogQuery::execute executed under staff actingAs
        $this->actingAs($this->staffA);
        $resultsStaff = AuditLogQuery::execute(logName: 'sensitive');
        $this->assertNotEmpty($resultsStaff);
        $this->assertEmpty($resultsStaff[0]['properties']);
    }

    public function test_pagination_is_stable_and_does_not_silently_truncate(): void
    {
        $this->actingAs($this->adminA);
        CompanyService::useCompanyForTests((int) $this->companyA->id);

        for ($i = 1; $i <= 15; $i++) {
            AuditService::log('stress', "bulk_action_{$i}");
        }

        // Test paginator with perPage = 5
        $page1 = AuditLogQuery::paginate(logName: 'stress', perPage: 5);
        $this->assertSame(5, $page1->count());
        $this->assertGreaterThanOrEqual(15, $page1->total());
        $this->assertSame(1, $page1->currentPage());

        // Test execute honors custom limit
        $limited = AuditLogQuery::execute(logName: 'stress', limit: 8);
        $this->assertCount(8, $limited);
    }

    public function test_legacy_backfill_quarantines_ambiguous_rows_and_backfills_proven_rows(): void
    {
        $this->actingAs($this->adminA);
        CompanyService::useCompanyForTests((int) $this->companyA->id);

        $customerA = Customer::factory()->create(['company_id' => $this->companyA->id]);

        // 1. Unambiguous legacy row with proven subject
        $provenId = DB::table('activity_log')->insertGetId([
            'company_id' => null,
            'log_name' => 'legacy',
            'description' => 'proven_legacy_event',
            'subject_type' => Customer::class,
            'subject_id' => $customerA->id,
            'causer_type' => User::class,
            'causer_id' => $this->adminA->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Ambiguous legacy row with conflicting hints: subject belongs to Company A, but properties claim Company B
        $ambiguousId = DB::table('activity_log')->insertGetId([
            'company_id' => null,
            'log_name' => 'legacy',
            'description' => 'ambiguous_legacy_event',
            'subject_type' => Customer::class,
            'subject_id' => $customerA->id,
            'properties' => json_encode(['company_id' => $this->companyB->id]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run the backfill helper method via a temporary instance of the migration
        $migration = require database_path('migrations/2026_09_07_000001_add_company_id_to_activity_log_table.php');
        $reflection = new \ReflectionClass($migration);
        $method = $reflection->getMethod('backfillCompanyIds');
        $method->setAccessible(true);
        $method->invoke($migration, config('activitylog.database_connection'), config('activitylog.table_name', 'activity_log'));

        // Assert proven row got backfilled to Company A
        $provenRow = DB::table('activity_log')->where('id', $provenId)->first();
        $this->assertSame((int) $this->companyA->id, (int) $provenRow->company_id);

        // Assert ambiguous row remained quarantined with NULL company_id
        $ambiguousRow = DB::table('activity_log')->where('id', $ambiguousId)->first();
        $this->assertNull($ambiguousRow->company_id);

        // Assert Tenant A and Tenant B never see the ambiguous quarantined row
        $resultsA = AuditLogQuery::execute(logName: 'legacy');
        $descriptionsA = array_column($resultsA, 'description');
        $this->assertContains('proven_legacy_event', $descriptionsA);
        $this->assertNotContains('ambiguous_legacy_event', $descriptionsA);

        $this->actingAs($this->adminB);
        CompanyService::useCompanyForTests((int) $this->companyB->id);
        $resultsB = AuditLogQuery::execute(logName: 'legacy');
        $descriptionsB = array_column($resultsB, 'description');
        $this->assertNotContains('proven_legacy_event', $descriptionsB);
        $this->assertNotContains('ambiguous_legacy_event', $descriptionsB);
    }

    public function test_auto_logs_activity_on_tenant_model_captures_company_id_server_authoritative(): void
    {
        $this->actingAs($this->adminA);
        CompanyService::useCompanyForTests((int) $this->companyA->id);

        // Direct Eloquent write on a model using LogsActivity without manual AuditService invocation
        $customer = Customer::factory()->create([
            'company_id' => $this->companyA->id,
            'name' => 'Auto Logged Customer',
        ]);

        $activity = Activity::query()
            ->where('subject_type', Customer::class)
            ->where('subject_id', $customer->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity, 'Spatie LogsActivity should have automatically logged the model event');
        $this->assertSame((int) $this->companyA->id, (int) $activity->company_id, 'Auto-logged activity must have server-authoritative company_id');

        // Test update event as well
        $customer->update(['name' => 'Updated Customer Name']);
        $updateActivity = Activity::query()
            ->where('subject_type', Customer::class)
            ->where('subject_id', $customer->id)
            ->where('description', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($updateActivity);
        $this->assertSame((int) $this->companyA->id, (int) $updateActivity->company_id);
    }

    public function test_contextless_payload_and_explicit_spoof_cannot_set_foreign_tenant(): void
    {
        // Clear all auth and company context
        auth()->logout();
        CompanyService::resetCache();
        CompanyService::useCompanyForTests(null);

        // 1. Contextless payload spoof attempting to set foreign tenant via properties['company_id']
        $activity1 = AuditService::log(
            module: 'security',
            action: 'contextless_payload_spoof',
            properties: ['company_id' => $this->companyB->id, 'attack' => 'spoof']
        );

        $this->assertNotNull($activity1);
        $this->assertNull($activity1->company_id, 'Contextless payload cannot set foreign tenant company_id');
        $props1 = $activity1->properties ? $activity1->properties->toArray() : [];
        $this->assertArrayNotHasKey('company_id', $props1, 'Spoofed company_id must not be stored in properties');

        // 2. Contextless explicit company_id argument without subject/context/auth proof
        $activity2 = AuditService::log(
            module: 'security',
            action: 'contextless_explicit_spoof',
            properties: ['foo' => 'bar'],
            companyId: (int) $this->companyB->id
        );

        $this->assertNotNull($activity2);
        $this->assertNull($activity2->company_id, 'Unproven explicit company_id cannot set foreign tenant');

        // 3. Conflicting explicit argument when subject belongs to Company A
        $customerA = Customer::factory()->create(['company_id' => $this->companyA->id]);
        $activity3 = AuditService::log(
            module: 'customer',
            action: 'conflicting_explicit_spoof',
            properties: ['note' => 'spoof test'],
            subject: $customerA,
            companyId: (int) $this->companyB->id
        );

        $this->assertNotNull($activity3);
        $this->assertSame((int) $this->companyA->id, (int) $activity3->company_id, 'Conflicting explicit argument must be rejected in favor of subject proof');
    }

    public function test_request_integration_over_500_rows_all_reachable_via_server_pagination(): void
    {
        $this->actingAs($this->adminA);
        CompanyService::useCompanyForTests((int) $this->companyA->id);

        // Bulk insert 520 rows for Company A
        $now = now();
        $batch = [];
        for ($i = 1; $i <= 520; $i++) {
            $batch[] = [
                'company_id' => $this->companyA->id,
                'log_name' => 'bulk_test',
                'description' => "bulk_reachability_row_{$i}",
                'causer_type' => User::class,
                'causer_id' => $this->adminA->id,
                'created_at' => $now->copy()->addSeconds($i),
                'updated_at' => $now->copy()->addSeconds($i),
            ];
            if (count($batch) >= 100) {
                DB::table('activity_log')->insert($batch);
                $batch = [];
            }
        }
        if (! empty($batch)) {
            DB::table('activity_log')->insert($batch);
        }

        // Request page 11 with per_page=50 (items 501-520)
        $response = $this->get(route('admin.reports.audit-log', [
            'log_name' => 'bulk_test',
            'page' => 11,
            'per_page' => 50,
            'sort_by' => 'created_at',
            'sort_dir' => 'asc',
        ]));

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $page->component('Admin/Reports/AuditLog')
                ->has('data.data', 20)
                ->where('data.meta.current_page', 11)
                ->where('data.meta.per_page', 50)
                ->where('data.meta.total', 520)
                ->where('data.meta.last_page', 11);
        });
    }

    public function test_tied_timestamps_page_1_and_2_disjoint_and_stable(): void
    {
        $this->actingAs($this->adminA);
        CompanyService::useCompanyForTests((int) $this->companyA->id);

        $frozenTime = now();
        $batch = [];
        for ($i = 1; $i <= 30; $i++) {
            $batch[] = [
                'company_id' => $this->companyA->id,
                'log_name' => 'tied_time',
                'description' => "tied_time_row_{$i}",
                'created_at' => $frozenTime,
                'updated_at' => $frozenTime,
            ];
        }
        DB::table('activity_log')->insert($batch);

        $page1 = AuditLogQuery::paginate(logName: 'tied_time', perPage: 15, sortField: 'created_at', sortDirection: 'desc');

        request()->merge(['page' => 2]);
        $page2Actual = AuditLogQuery::paginate(logName: 'tied_time', perPage: 15, sortField: 'created_at', sortDirection: 'desc');

        $ids1 = collect($page1->items())->pluck('id')->all();
        $ids2 = collect($page2Actual->items())->pluck('id')->all();

        $this->assertCount(15, $ids1);
        $this->assertCount(15, $ids2);
        $intersection = array_intersect($ids1, $ids2);
        $this->assertEmpty($intersection, 'Page 1 and Page 2 must be disjoint with ID tie-breaker even when timestamps are tied');
    }

    public function test_search_sort_page_are_authoritatively_server_side(): void
    {
        $this->actingAs($this->adminA);
        CompanyService::useCompanyForTests((int) $this->companyA->id);

        for ($i = 1; $i <= 10; $i++) {
            AuditService::log('server_side', "unique_needle_target_{$i}");
            AuditService::log('server_side', "other_noise_row_{$i}");
        }

        // Test search server-side
        $responseSearch = $this->get(route('admin.reports.audit-log', [
            'search' => 'unique_needle_target',
            'per_page' => 5,
            'page' => 1,
            'sort_by' => 'id',
            'sort_dir' => 'asc',
        ]));

        $responseSearch->assertOk();
        $responseSearch->assertInertia(function ($page) {
            $page->component('Admin/Reports/AuditLog')
                ->has('data.data', 5)
                ->where('data.meta.total', 10)
                ->where('data.meta.last_page', 2)
                ->where('filters.search', 'unique_needle_target')
                ->where('filters.sort_by', 'id');
        });

        // Test page 2 of search
        $responsePage2 = $this->get(route('admin.reports.audit-log', [
            'search' => 'unique_needle_target',
            'per_page' => 5,
            'page' => 2,
            'sort_by' => 'id',
            'sort_dir' => 'asc',
        ]));

        $responsePage2->assertOk();
        $responsePage2->assertInertia(function ($page) {
            $page->component('Admin/Reports/AuditLog')
                ->has('data.data', 5)
                ->where('data.meta.current_page', 2)
                ->where('data.data.0.description', 'unique_needle_target_6');
        });
    }

    public function test_ui_sort_keys_are_all_valid_and_produce_no_validation_errors(): void
    {
        $this->actingAs($this->adminA);

        $allowedSortKeys = ['created_at', 'log_name', 'event', 'description', 'id'];

        foreach ($allowedSortKeys as $sortKey) {
            foreach (['asc', 'desc'] as $direction) {
                $response = $this->get(route('admin.reports.audit-log', [
                    'sort' => $sortKey,
                    'direction' => $direction,
                    'sort_by' => $sortKey,
                    'sort_dir' => $direction,
                ]));

                $response->assertOk();
                $response->assertInertia(function ($page) use ($sortKey, $direction) {
                    $page->component('Admin/Reports/AuditLog')
                        ->where('filters.sort_by', $sortKey)
                        ->where('filters.sort_dir', $direction);
                });
            }
        }
    }

    public function test_invalid_sort_keys_such_as_causer_and_subject_are_rejected(): void
    {
        $this->actingAs($this->adminA);

        foreach (['causer', 'subject', 'invalid_column', 'password'] as $invalidSort) {
            $response = $this->from(route('admin.reports.audit-log'))
                ->get(route('admin.reports.audit-log', [
                    'sort' => $invalidSort,
                ]));

            $response->assertSessionHasErrors(['sort']);

            $jsonResponse = $this->getJson(route('admin.reports.audit-log', [
                'sort' => $invalidSort,
            ]));

            $jsonResponse->assertStatus(422)
                ->assertJsonValidationErrors(['sort']);
        }
    }

    public function test_causer_search_filters_global_dataset_across_multiple_pages_tenant_safe(): void
    {
        $targetCauserA = User::factory()->create([
            'company_id' => $this->companyA->id,
            'name' => 'Agent Hunter Special',
            'email' => 'hunter.special@companya.test',
        ]);

        $otherUserA = User::factory()->create([
            'company_id' => $this->companyA->id,
            'name' => 'Other Person A',
            'email' => 'other.person@companya.test',
        ]);

        $targetCauserB = User::factory()->create([
            'company_id' => $this->companyB->id,
            'name' => 'Agent Hunter Special',
            'email' => 'hunter.special@companyb.test',
        ]);

        // Create 12 activities caused by $targetCauserA
        $this->actingAs($targetCauserA);
        for ($i = 1; $i <= 12; $i++) {
            AuditService::log('security', "agent_action_targeted_{$i}");
        }

        // Create 10 activities caused by $otherUserA (noise in company A)
        $this->actingAs($otherUserA);
        for ($i = 1; $i <= 10; $i++) {
            AuditService::log('security', "other_action_noise_{$i}");
        }

        // Create 10 activities caused by $targetCauserB in company B (cross-tenant leak check)
        $this->actingAs($targetCauserB);
        for ($i = 1; $i <= 10; $i++) {
            AuditService::log('security', "b_action_targeted_{$i}");
        }

        // Query as Admin A searching for 'Agent Hunter Special'
        $this->actingAs($this->adminA);

        $page1Response = $this->get(route('admin.reports.audit-log', [
            'search' => 'Agent Hunter Special',
            'per_page' => 5,
            'page' => 1,
            'sort_by' => 'id',
            'sort_dir' => 'asc',
        ]));

        $page1Response->assertOk();
        $page1Data = $page1Response->viewData('page')['props']['data'];

        $this->assertSame(12, $page1Data['meta']['total'], 'Total matching records across all pages must be exactly 12');
        $this->assertSame(3, $page1Data['meta']['last_page']);
        $this->assertCount(5, $page1Data['data']);

        $page1Ids = [];
        foreach ($page1Data['data'] as $row) {
            $this->assertSame($this->companyA->id, $row['company_id']);
            $this->assertSame($targetCauserA->id, $row['causer_id']);
            $page1Ids[] = $row['id'];
        }

        // Query Page 2
        $page2Response = $this->get(route('admin.reports.audit-log', [
            'search' => 'Agent Hunter Special',
            'per_page' => 5,
            'page' => 2,
            'sort_by' => 'id',
            'sort_dir' => 'asc',
        ]));

        $page2Response->assertOk();
        $page2Data = $page2Response->viewData('page')['props']['data'];
        $this->assertCount(5, $page2Data['data']);

        $page2Ids = [];
        foreach ($page2Data['data'] as $row) {
            $this->assertSame($this->companyA->id, $row['company_id']);
            $this->assertSame($targetCauserA->id, $row['causer_id']);
            $page2Ids[] = $row['id'];
        }

        // Query Page 3
        $page3Response = $this->get(route('admin.reports.audit-log', [
            'search' => 'Agent Hunter Special',
            'per_page' => 5,
            'page' => 3,
            'sort_by' => 'id',
            'sort_dir' => 'asc',
        ]));

        $page3Response->assertOk();
        $page3Data = $page3Response->viewData('page')['props']['data'];
        $this->assertCount(2, $page3Data['data']);

        $page3Ids = [];
        foreach ($page3Data['data'] as $row) {
            $this->assertSame($this->companyA->id, $row['company_id']);
            $this->assertSame($targetCauserA->id, $row['causer_id']);
            $page3Ids[] = $row['id'];
        }

        // Disjointness check: Page 1, Page 2, Page 3 must have mutually disjoint IDs
        $this->assertEmpty(array_intersect($page1Ids, $page2Ids), 'Page 1 and Page 2 must be disjoint');
        $this->assertEmpty(array_intersect($page2Ids, $page3Ids), 'Page 2 and Page 3 must be disjoint');
        $this->assertEmpty(array_intersect($page1Ids, $page3Ids), 'Page 1 and Page 3 must be disjoint');

        // Total collected unique IDs must be 12
        $allCollected = array_merge($page1Ids, $page2Ids, $page3Ids);
        $this->assertCount(12, array_unique($allCollected));
    }

    public function test_company_seeder_acquires_context_and_seeds_locations_without_prior_auth_session(): void
    {
        auth()->logout();
        CompanyService::resetCache();

        $seeder = new CompanySeeder();
        $seeder->runFor($this->companyA);

        $this->assertDatabaseHas('locations', [
            'company_id' => $this->companyA->id,
            'name' => 'Default Region',
        ]);
    }
}
