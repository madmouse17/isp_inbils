<?php

namespace Tests\Feature\Api\V1\Customer;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderAssignment;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * Spec F5: the customer follows the technician's visit. Own subscriptions only, an allow-list, and of the technician
 * the first name and nothing else: never the phone, the surname or the account.
 */
class WorkOrdersTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const TECHNICIAN_PHONE = '0813-5555-0001';

    private const ASSIGNED_AT = '2026-10-16 01:00:00';

    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'status', 'title', 'description', 'priority', 'result', 'rejection_reason', 'assigned_to', 'assignee', 'created_by', 'approved_by',
        'customer_id', 'subscription_id', 'location_id', 'location', 'ticket_id', 'items', 'evidence', 'phone', 'phone_e164', 'email', 'name',
        'lat', 'lng', 'check_in',
    ];

    private User $user;

    private Customer $customer;

    private string $token;

    private User $technician;

    /** @var array<int, ServiceSubscription> one subscription per customer, so their visits share it */
    private array $subscriptions = [];

    protected function setUp(): void
    {
        parent::setUp();

        [$this->user, $this->customer] = $this->customerLogin();
        $this->token = $this->issueToken($this->user, 'customer');
        $this->technician = $this->technicianLogin($this->user->company, ['name' => 'Andi Wijaya']);
        $this->technician->employeeProfile()->update(['phone' => self::TECHNICIAN_PHONE]);
    }

    public function test_the_list_holds_the_open_visits_and_the_closed_ones_of_the_last_thirty_days(): void
    {
        $assigned = $this->visit('assigned');
        $working = $this->visit('in_progress');
        $review = $this->visit('waiting_review');
        $rework = $this->visit('rejected');
        $recentlyDone = $this->visit('completed', ['updated_at' => now()->subDays(29)]);
        $recentlyCancelled = $this->visit('cancelled', ['updated_at' => now()->subDays(2)]);
        $this->visit('completed', ['updated_at' => now()->subDays(31)]);
        $this->visit('cancelled', ['updated_at' => now()->subDays(45)]);
        $this->visit('draft');
        $this->visit('generated');

        $response = $this->api('GET', 'customer/work-orders', token: $this->token)
            ->assertOk()
            ->assertJsonPath('meta.total', 6);

        $this->assertSame(
            collect([$recentlyCancelled, $recentlyDone, $rework, $review, $working, $assigned])->pluck('public_id')->all(),
            $response->json('data.*.id'),
            'newest first',
        );
    }

    public function test_only_the_customers_own_subscriptions_show(): void
    {
        $mine = $this->visit('assigned');
        $otherCustomer = Customer::factory()->create(['company_id' => $this->customer->company_id]);
        $this->visit('assigned', owner: $otherCustomer);
        $this->visit('in_progress', owner: $otherCustomer);
        $this->visit('assigned', owner: $this->customerOfAnotherCompany());
        // For this customer but not for a subscription of theirs: the app shows subscription visits only.
        $this->visit('assigned', subscribed: false);

        $this->api('GET', 'customer/work-orders', token: $this->token)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $mine->public_id);
    }

    public function test_a_visit_that_is_not_theirs_is_a_404(): void
    {
        $otherCustomer = Customer::factory()->create(['company_id' => $this->customer->company_id]);
        $peers = $this->visit('assigned', owner: $otherCustomer);
        $strangers = $this->visit('assigned', owner: $this->customerOfAnotherCompany());
        $withoutSubscription = $this->visit('assigned', subscribed: false);
        $draft = $this->visit('draft');
        $generated = $this->visit('generated');

        foreach ([$peers, $strangers, $withoutSubscription, $draft, $generated] as $visit) {
            $this->api('GET', "customer/work-orders/{$visit->public_id}", token: $this->token)->assertNotFound();
        }

        $this->api('GET', 'customer/work-orders/9999999', token: $this->token)->assertNotFound();
        $this->api('GET', 'customer/work-orders/abc', token: $this->token)->assertNotFound();
    }

    public function test_the_list_item_and_the_detail_are_the_same_allow_list(): void
    {
        $visit = $this->visit('in_progress', ['scheduled_date' => '2026-10-20', 'started_at' => '2026-10-16 02:00:00']);
        $keys = ['id', 'code', 'type', 'scheduled_date', 'state', 'steps', 'current', 'technician', 'installation_address'];

        $item = $this->api('GET', 'customer/work-orders', token: $this->token)->assertOk()->json('data.0');
        $detail = $this->api('GET', "customer/work-orders/{$visit->public_id}", token: $this->token)->assertOk()->json('data');

        $this->assertSame($keys, array_keys($item));
        $this->assertSame($item, $detail);
        $this->assertSame($visit->code, $detail['code']);
        $this->assertSame(['key' => 'installation', 'label' => 'Instalasi'], $detail['type']);
        $this->assertSame('2026-10-20', $detail['scheduled_date']);
        $this->assertSame(['key', 'label', 'at'], array_keys($detail['steps'][0]));
        $this->assertSame(['first_name'], array_keys($detail['technician']));
        $this->assertSame(['label' => 'Rumah', 'address' => 'Jl. Mawar 1', 'city' => 'Jakarta'], $detail['installation_address']);
        $this->assertForbiddenKeysAbsent($detail);
    }

    public function test_of_the_technician_the_customer_learns_the_first_name_and_nothing_else(): void
    {
        $visit = $this->visit('assigned');

        $private = [self::TECHNICIAN_PHONE, '081355550001', '6281355550001', 'Wijaya', $this->technician->email];

        foreach (['customer/work-orders' => 'data.0.technician.first_name', "customer/work-orders/{$visit->public_id}" => 'data.technician.first_name'] as $uri => $path) {
            $response = $this->api('GET', $uri, token: $this->token)->assertOk();

            $this->assertSame('Andi', $response->json($path));
            foreach ($private as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent(), "{$uri} tells the customer {$secret}");
            }
        }
    }

    public function test_a_visit_without_a_technician_has_a_null_technician(): void
    {
        $this->visit('assigned', ['assigned_to' => null]);

        $this->api('GET', 'customer/work-orders', token: $this->token)->assertOk()->assertJsonPath('data.0.technician', null);
    }

    public function test_the_steps_follow_the_work_and_each_reached_step_carries_its_time(): void
    {
        $visit = $this->visit('assigned');
        $at = fn (string $time): string => Carbon::parse($time)->jsonSerialize();
        $detail = fn (): array => $this->api('GET', "customer/work-orders/{$visit->public_id}", token: $this->token)->assertOk()->json('data');
        $times = fn (array $data): array => array_column($data['steps'], 'at');

        $data = $detail();
        $this->assertSame('open', $data['state']);
        $this->assertSame('assigned', $data['current']);
        $this->assertSame(['assigned', 'en_route', 'in_progress', 'installed'], array_column($data['steps'], 'key'));
        $this->assertSame(['Ditugaskan', 'Dalam perjalanan', 'Sedang dikerjakan', 'Selesai dipasang'], array_column($data['steps'], 'label'));
        $this->assertSame([$at(self::ASSIGNED_AT), null, null, null], $times($data));

        $visit->forceFill(['en_route_at' => '2026-10-16 01:30:00'])->save();
        $data = $detail();
        $this->assertSame('en_route', $data['current']);
        $this->assertSame([$at(self::ASSIGNED_AT), $at('2026-10-16 01:30:00'), null, null], $times($data));

        $visit->forceFill(['status' => 'in_progress', 'started_at' => '2026-10-16 02:00:00'])->save();
        $data = $detail();
        $this->assertSame(['open', 'in_progress'], [$data['state'], $data['current']]);
        $this->assertSame([$at(self::ASSIGNED_AT), $at('2026-10-16 01:30:00'), $at('2026-10-16 02:00:00'), null], $times($data));

        $visit->forceFill(['status' => 'waiting_review', 'submitted_at' => '2026-10-16 04:00:00'])->save();
        $data = $detail();
        $this->assertSame(['done', 'installed'], [$data['state'], $data['current']], 'the office review is not the customer\'s concern');
        $this->assertSame([$at(self::ASSIGNED_AT), $at('2026-10-16 01:30:00'), $at('2026-10-16 02:00:00'), $at('2026-10-16 04:00:00')], $times($data));

        $visit->forceFill(['status' => 'completed'])->save();
        $data = $detail();
        $this->assertSame(['done', 'installed'], [$data['state'], $data['current']]);
    }

    public function test_work_sent_back_shows_as_still_being_worked_on_and_never_says_why(): void
    {
        $visit = $this->visit('rejected', [
            'started_at' => '2026-10-16 02:00:00',
            'submitted_at' => '2026-10-16 04:00:00',
            'rejection_reason' => 'SECRET-photos-are-blurry',
        ]);

        $response = $this->api('GET', "customer/work-orders/{$visit->public_id}", token: $this->token)->assertOk();

        $this->assertSame(['open', 'in_progress', null], [$response->json('data.state'), $response->json('data.current'), $response->json('data.steps.3.at')]);
        $this->assertStringNotContainsString('SECRET-photos-are-blurry', $response->getContent());
    }

    public function test_a_cancelled_visit_has_no_steps(): void
    {
        $visit = $this->visit('cancelled', ['started_at' => '2026-10-16 02:00:00']);

        $this->api('GET', "customer/work-orders/{$visit->public_id}", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.state', 'cancelled')
            ->assertJsonPath('data.current', null)
            ->assertJsonPath('data.steps', []);
    }

    public function test_an_old_closed_visit_is_hidden_from_the_list_but_still_opens_from_its_notification(): void
    {
        $old = $this->visit('completed', ['updated_at' => now()->subDays(60)]);

        $this->api('GET', 'customer/work-orders', token: $this->token)->assertOk()->assertJsonPath('meta.total', 0);
        $this->api('GET', "customer/work-orders/{$old->public_id}", token: $this->token)->assertOk()->assertJsonPath('data.id', $old->public_id);
    }

    public function test_the_list_is_paginated(): void
    {
        $this->visit('assigned');
        $this->visit('in_progress');
        $this->visit('waiting_review');

        $this->api('GET', 'customer/work-orders?per_page=2', token: $this->token)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2);
        $this->api('GET', 'customer/work-orders?per_page=51', token: $this->token)->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    }

    public function test_a_technician_token_and_a_call_without_a_token_are_refused(): void
    {
        $this->visit('assigned');

        $this->api('GET', 'customer/work-orders', token: $this->issueToken($this->technician, 'technician'))->assertForbidden();
        $this->api('GET', 'customer/work-orders')->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $columns  work_orders columns
     */
    private function visit(string $status, array $columns = [], ?Customer $owner = null, bool $subscribed = true): WorkOrder
    {
        $owner ??= $this->customer;
        $companyId = (int) $owner->company_id;
        CompanyService::useCompanyForTests($companyId);

        $subscription = $subscribed ? ($this->subscriptions[$owner->id] ??= ServiceSubscription::factory()->create([
            'company_id' => $companyId,
            'customer_id' => $owner->id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $companyId])->id,
            'installation_address_id' => CustomerAddress::factory()->create([
                'company_id' => $companyId,
                'customer_id' => $owner->id,
                'label' => 'Rumah',
                'address' => 'Jl. Mawar 1',
                'city' => 'Jakarta',
            ])->id,
        ])) : null;

        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $companyId,
            'type' => 'installation',
            'status' => $status,
            'customer_id' => $owner->id,
            'subscription_id' => $subscription?->id,
            'assigned_to' => $this->technician->id,
            'created_by' => $this->technician->id,
            ...$columns,
        ]);
        WorkOrderAssignment::forceCreate([
            'company_id' => $companyId,
            'work_order_id' => $workOrder->id,
            'technician_id' => $this->technician->id,
            'assigned_by' => $this->technician->id,
            'assigned_at' => self::ASSIGNED_AT,
        ]);

        return $workOrder;
    }

    private function customerOfAnotherCompany(): Customer
    {
        return Customer::factory()->create(['company_id' => Company::factory()->create(['is_active' => true])->id]);
    }

    /** @param  array<int|string, mixed>  $payload */
    private function assertForbiddenKeysAbsent(array $payload): void
    {
        foreach ($payload as $key => $value) {
            $this->assertNotContains($key, self::FORBIDDEN_KEYS, "the response carries {$key}");
            if (is_array($value)) {
                $this->assertForbiddenKeysAbsent($value);
            }
        }
    }
}
