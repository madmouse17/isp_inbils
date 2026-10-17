<?php

namespace Tests\Feature\PublicId;

use App\Models\Core\EmployeeEvaluation;
use App\Models\User;
use App\Services\Core\ApiTokenService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;
use Tests\Traits\InteractsWithMobileApi;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * Spec .scratch/opaque-ids K4: record-level 404s (a public id that names nothing, a record the policy hides) are
 * counted per user, per address when nobody is signed in. Past the threshold one audit entry is written and every
 * request is refused with 429 until the cooldown ends. A missing permission (403) never counts.
 */
class DetectRecordEnumerationTest extends TestCase
{
    use BuildsCompanyGroup;
    use InteractsWithMobileApi;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
        $this->admin = $this->groupUser($this->head);
    }

    public function test_it_is_off_in_testing_unless_a_test_switches_it_on(): void
    {
        $this->assertFalse(config('security.enumeration.enabled'));

        $this->actingAs($this->admin);

        foreach (range(1, 40) as $_) {
            $this->get(route('admin.customers.show', $this->unknownId()))->assertNotFound();
        }

        $this->assertSame(0, $this->suspicionEntries()->count());
    }

    public function test_misses_past_the_threshold_are_refused_with_429_and_logged_once(): void
    {
        $this->enable(threshold: 3);
        $this->actingAs($this->admin);

        foreach (range(1, 3) as $_) {
            $this->get(route('admin.customers.show', $this->unknownId()))->assertNotFound();
        }

        $tried = $this->unknownId();
        $this->get(route('admin.customers.show', $tried))->assertStatus(429)->assertHeader('Retry-After');
        // A blocked user is refused on every request, a page that exists included, and nothing more is logged.
        $this->get(route('admin.dashboard'))->assertStatus(429);
        $this->get(route('admin.customers.show', $this->unknownId()))->assertStatus(429);

        $entries = $this->suspicionEntries();
        $this->assertCount(1, $entries);

        $entry = $entries->first();
        $this->assertSame('security', $entry->log_name);
        $this->assertSame($this->admin->id, $entry->causer_id);
        $this->assertSame('admin.customers.show', $entry->properties['route']);
        $this->assertSame(['customer' => $tried], $entry->properties['ids']);
        $this->assertSame(4, $entry->properties['count']);
    }

    public function test_the_block_ends_after_the_cooldown(): void
    {
        $this->enable(threshold: 2, cooldown: 10);
        $this->actingAs($this->admin);

        foreach (range(1, 3) as $_) {
            $this->get(route('admin.customers.show', $this->unknownId()));
        }
        $this->get(route('admin.dashboard'))->assertStatus(429);

        $this->travel(9)->minutes();
        $this->get(route('admin.dashboard'))->assertStatus(429);

        $this->travel(2)->minutes();
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_the_window_rolls_misses_older_than_it_no_longer_count(): void
    {
        $this->enable(threshold: 3, window: 5);
        $this->actingAs($this->admin);

        foreach (range(1, 3) as $_) {
            $this->get(route('admin.customers.show', $this->unknownId()))->assertNotFound();
        }

        $this->travel(6)->minutes();

        foreach (range(1, 3) as $_) {
            $this->get(route('admin.customers.show', $this->unknownId()))->assertNotFound();
        }

        $this->get(route('admin.customers.show', $this->unknownId()))->assertStatus(429);
    }

    public function test_a_missing_permission_is_not_counted(): void
    {
        $this->enable(threshold: 2);
        $staff = $this->groupUser($this->head, 'staff');
        $this->actingAs($staff);

        foreach (range(1, 6) as $_) {
            $this->get(route('admin.users.index'))->assertForbidden();
        }

        $this->assertSame(0, $this->suspicionEntries()->count());
    }

    public function test_a_record_the_policy_hides_is_counted(): void
    {
        $this->enable(threshold: 2);
        $technician = $this->groupUser($this->head, 'technician');
        $colleague = $this->groupUser($this->head, 'technician');
        $evaluation = EmployeeEvaluation::factory()->create([
            'company_id' => $this->head->id,
            'employee_id' => $colleague->id,
            'evaluator_id' => $this->admin->id,
        ]);

        $this->actingAs($technician);

        $this->get(route('admin.evaluations.show', $evaluation))->assertNotFound();
        $this->get(route('admin.evaluations.show', $evaluation))->assertNotFound();
        $this->get(route('admin.evaluations.show', $evaluation))->assertStatus(429);
    }

    public function test_every_user_has_their_own_budget(): void
    {
        $this->enable(threshold: 2);
        $other = $this->groupUser($this->head);

        $this->actingAs($this->admin);
        foreach (range(1, 3) as $_) {
            $this->get(route('admin.customers.show', $this->unknownId()));
        }
        $this->get(route('admin.dashboard'))->assertStatus(429);

        $this->actingAs($other)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_the_api_answers_json_with_a_retry_after(): void
    {
        $this->enable(threshold: 2);
        [$user] = $this->customerLogin($this->head);
        $token = $this->issueToken($user, ApiTokenService::CUSTOMER);

        foreach (range(1, 2) as $_) {
            $this->api('GET', 'customer/subscriptions/'.$this->unknownId(), token: $token)->assertNotFound()->assertJson(['code' => 'not_found']);
        }

        $this->api('GET', 'customer/subscriptions/'.$this->unknownId(), token: $token)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJson(['code' => 'too_many_requests']);
        $this->api('GET', 'me', token: $token)->assertStatus(429);
    }

    public function test_a_visitor_who_is_not_signed_in_is_never_counted_or_blocked(): void
    {
        $this->enable(threshold: 30);

        // The isolir page lets 20 unknown codes a minute through per address, so the 31 take two minutes.
        foreach (range(1, 31) as $attempt) {
            $this->get(route('isolir.show', 'nosuchco'.$attempt))->assertNotFound();

            if ($attempt === 20) {
                $this->travel(61)->seconds();
            }
        }

        $this->get(route('login'))->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'x', 'device_name' => 'Pixel', 'app' => 'customer'])->assertUnprocessable();
        $this->assertSame(0, $this->suspicionEntries()->count());
    }

    public function test_a_miss_asked_for_as_json_on_a_web_route_is_counted_too(): void
    {
        $this->enable(threshold: 2);
        $this->actingAs($this->admin);

        foreach (range(1, 2) as $_) {
            $this->getJson(route('admin.customers.show', $this->unknownId()))->assertNotFound();
        }

        $this->getJson(route('admin.customers.show', $this->unknownId()))->assertStatus(429);
    }

    private function enable(int $threshold, int $window = 5, int $cooldown = 10): void
    {
        config(['security.enumeration' => ['enabled' => true, 'threshold' => $threshold, 'window' => $window, 'cooldown' => $cooldown]]);
    }

    private function unknownId(): string
    {
        return strtolower((string) Str::ulid());
    }

    /** @return Collection<int, Activity> */
    private function suspicionEntries()
    {
        return Activity::query()->where('log_name', 'security')->where('description', 'enumeration_suspected')->get();
    }
}
