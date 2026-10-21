<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\User;
use App\Support\OptimisticLock;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * F1-U05: the first save of Pengaturan Perusahaan stamps `billing_defaults_confirmed_at`, on the server side. The stamp
 * is what the dashboard checklist and the billing callout read; a client can neither set it nor move it.
 */
class CompanySettingsBillingMarkerTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'billing_defaults_confirmed_at';

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
        $this->travelTo(Carbon::create(2026, 10, 20, 9, 30, 0, 'UTC'));

        $this->company = Company::factory()->create(['is_active' => true, 'settings' => ['tax_ppn_rate' => 11]]);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($this->admin, 'admin');
    }

    /** @param array<string, mixed> $settings */
    private function save(array $settings): TestResponse
    {
        return $this->actingAs($this->admin)->put(route('admin.company.settings.update'), [
            'settings' => $settings,
            OptimisticLock::FIELD => OptimisticLock::version($this->company->fresh()),
        ]);
    }

    private function stamp(): ?string
    {
        return $this->company->fresh()->settings[self::KEY] ?? null;
    }

    public function test_the_page_shows_no_marker_until_a_save_has_stamped_it(): void
    {
        $this->actingAs($this->admin)->get(route('admin.company.settings.edit'))
            ->assertInertia(fn (Assert $page) => $page->missing('settings.'.self::KEY));

        $this->save(['tax_ppn_rate' => 12])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('admin.company.settings.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('settings.'.self::KEY, now()->toIso8601String()));
    }

    public function test_first_save_stamps_marker(): void
    {
        $this->save(['tax_ppn_rate' => 12])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(now()->toIso8601String(), $this->stamp());
        $this->assertSame(12, $this->company->fresh()->settings['tax_ppn_rate'], 'the save itself still lands');
    }

    public function test_the_stamp_is_never_moved_by_a_later_save(): void
    {
        $this->save(['tax_ppn_rate' => 12])->assertRedirect();
        $first = $this->stamp();

        $this->travel(3)->days();
        $this->save(['tax_ppn_rate' => 13])->assertRedirect();

        $this->assertSame($first, $this->stamp());
        $this->assertSame(13, $this->company->fresh()->settings['tax_ppn_rate']);
    }

    public function test_client_marker_is_dropped(): void
    {
        $this->save([self::KEY => '2020-01-01T00:00:00+00:00', 'tax_ppn_rate' => 12])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(now()->toIso8601String(), $this->stamp(), 'the stored stamp is the server clock, not the sent value');

        $this->travel(1)->days();
        $stored = $this->stamp();
        foreach (['1999-12-31T00:00:00+00:00', null, ''] as $sent) {
            $this->save([self::KEY => $sent, 'tax_ppn_rate' => 14])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($stored, $this->stamp(), 'a client copy never moves or clears the stamp');
        }
    }

    public function test_a_refused_save_stamps_nothing(): void
    {
        $this->save(['map_lat' => 40, 'map_lng' => 107])->assertSessionHasErrors('settings.map_lat');

        $this->assertNull($this->stamp());
    }

    public function test_a_save_from_an_older_copy_stamps_nothing(): void
    {
        $url = route('admin.company.settings.update');
        $rendered = OptimisticLock::version($this->company);
        $this->company->forceFill(['updated_at' => now()->addMinute()])->saveQuietly();

        $this->actingAs($this->admin)->putJson($url, ['settings' => ['tax_ppn_rate' => 12], OptimisticLock::FIELD => $rendered])->assertStatus(409);

        $this->assertNull($this->stamp());
    }
}
