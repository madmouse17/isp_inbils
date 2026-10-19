<?php

namespace Tests\Feature\Api\V1\Platform;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Password;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\EnrollsTwoFactor;
use Tests\Traits\InteractsWithMobileApi;

/**
 * A lost phone must not outlive the credential or the account: every way the password changes, the user is
 * deactivated or deleted, or the second factor changes leaves the user with no token, and the next call is a 401.
 */
class TokenRevocationTest extends TestCase
{
    use CreatesCompanyUser;
    use EnrollsTwoFactor;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const NEW_PASSWORD = 'N3w-Passw0rd-xyz';

    private User $admin;

    private Company $company;

    private User $user;

    /** @var list<string> */
    private array $tokens;

    private string $bystanderToken;

    private User $bystander;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 12, 10, 0, 0, 'UTC'));
        $this->admin = $this->createCompanyUser();
        $this->company = Company::query()->findOrFail($this->admin->company_id);

        [$this->user] = $this->customerLogin($this->company);
        $this->tokens = [$this->issueToken($this->user, 'customer', 'Phone one'), $this->issueToken($this->user, 'customer', 'Phone two')];

        [$this->bystander] = $this->customerLogin($this->company);
        $this->bystanderToken = $this->issueToken($this->bystander, 'customer');
    }

    public function test_a_password_reset_from_the_emailed_link_revokes_every_token(): void
    {
        $this->post('/reset-password', [
            'token' => Password::createToken($this->user),
            'email' => $this->user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('login'));

        $this->assertRevoked();
        $this->assertBystanderUntouched();
    }

    public function test_the_profile_password_form_revokes_every_token(): void
    {
        $this->actingAs($this->user)->from('/profile')->put('/password', [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertRevoked();
        $this->assertBystanderUntouched();
    }

    public function test_an_admin_changing_the_password_revokes_every_token(): void
    {
        $this->actingAs($this->admin)->put(route('admin.users.update', $this->user), [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'password' => self::NEW_PASSWORD,
            'is_active' => true,
            'roles' => ['customer'],
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));

        $this->assertRevoked();
        $this->assertBystanderUntouched();
    }

    public function test_an_admin_deactivating_the_user_revokes_every_token(): void
    {
        $this->actingAs($this->admin)->put(route('admin.users.update', $this->user), [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'is_active' => false,
            'roles' => ['customer'],
        ])->assertSessionHasNoErrors();

        $this->assertFalse($this->user->fresh()->is_active);
        $this->assertRevoked();
        $this->assertBystanderUntouched();
    }

    public function test_deleting_the_user_leaves_no_token_behind(): void
    {
        $plain = User::factory()->create(['company_id' => $this->company->id]);
        $token = $plain->createToken('Phone', ['customer'])->plainTextToken;

        $this->actingAs($this->admin)->delete(route('admin.users.destroy', $plain))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('users', ['id' => $plain->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $plain->id, 'tokenable_type' => User::class]);
        $this->api('GET', 'me', token: $token)->assertUnauthorized();
    }

    public function test_every_writer_of_the_columns_is_covered_because_the_hook_sits_on_the_model(): void
    {
        $this->user->update(['name' => 'Renamed', 'email' => 'renamed@example.test']);
        $this->user->forceFill(['remember_token' => 'rotated', 'last_login_at' => now(), 'email_verified_at' => null])->save();
        $this->user->update(['is_active' => true]);
        $this->assertSame(2, $this->user->tokens()->count(), 'unrelated writes keep the logins');

        $this->user->forceFill(['password' => self::NEW_PASSWORD])->save();

        $this->assertRevoked();
    }

    public function test_turning_two_factor_on_revokes_but_starting_the_setup_does_not(): void
    {
        $service = app(TwoFactorService::class);
        $secret = $service->beginEnrollment($this->user)['setup_key'];
        $this->assertSame(2, $this->user->tokens()->count(), 'a pending setup changes nothing for other logins');

        $service->confirm($this->user, $this->totp($secret));

        $this->assertRevoked();
        $this->assertBystanderUntouched();
    }

    public function test_regenerating_codes_turning_it_off_and_an_admin_reset_each_revoke(): void
    {
        $this->enrollTwoFactor($this->user);
        $service = app(TwoFactorService::class);

        foreach ([
            'recovery codes regenerated' => fn () => $service->regenerateRecoveryCodes($this->user),
            'turned off' => fn () => $service->disable($this->user),
            'admin reset' => function () use ($service): void {
                $this->enrollTwoFactor($this->user);
                $this->reissueTokens();
                $service->adminReset($this->admin, $this->user);
            },
        ] as $label => $change) {
            $this->reissueTokens();
            $this->assertSame(2, $this->user->tokens()->count(), $label);

            $change();

            $this->assertRevoked($label);
        }

        $this->assertBystanderUntouched();
    }

    public function test_cancelling_a_pending_setup_or_resetting_a_user_without_two_factor_keeps_the_logins(): void
    {
        $service = app(TwoFactorService::class);
        $service->beginEnrollment($this->user);

        $service->disable($this->user);
        $service->adminReset($this->admin, $this->user);

        $this->assertSame(2, $this->user->tokens()->count());
    }

    public function test_each_revocation_is_audited_with_its_reason_and_never_with_a_token(): void
    {
        $this->user->forceFill(['password' => self::NEW_PASSWORD])->save();
        $this->user->forceFill(['is_active' => true])->save();

        $audit = Activity::query()->where('description', 'api.tokens_revoked')->sole();
        $this->assertSame('security', $audit->log_name);
        $this->assertSame($this->user->id, $audit->subject_id);
        $this->assertSame($this->company->id, (int) $audit->company_id, 'the company lives in its column, not in the properties');
        $this->assertEqualsCanonicalizing(
            ['user_id' => $this->user->id, 'reason' => 'password_changed', 'count' => 2],
            $audit->properties->all(),
        );
        $this->assertSame(2, Activity::query()->where('description', 'api.token_issued')->where('subject_id', $this->user->id)->count());

        foreach (Activity::query()->get() as $entry) {
            $text = json_encode([$entry->description, $entry->properties]);
            foreach ([...$this->tokens, $this->bystanderToken] as $plain) {
                $this->assertStringNotContainsString(explode('|', $plain, 2)[1], $text);
                $this->assertStringNotContainsString(hash('sha256', explode('|', $plain, 2)[1]), $text);
            }
        }
    }

    public function test_a_user_without_tokens_leaves_no_revocation_noise(): void
    {
        $plain = User::factory()->create(['company_id' => $this->company->id]);

        $plain->update(['password' => self::NEW_PASSWORD]);
        $plain->update(['is_active' => false]);
        $plain->delete();

        $this->assertSame(0, Activity::query()->where('description', 'api.tokens_revoked')->count());
    }

    private function assertRevoked(string $label = ''): void
    {
        $this->assertSame(0, $this->user->tokens()->count(), $label.' leaves no token');

        foreach ($this->tokens as $token) {
            $this->api('GET', 'me', token: $token)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        }
    }

    private function assertBystanderUntouched(): void
    {
        $this->assertSame(1, $this->bystander->tokens()->count());
        $this->api('GET', 'me', token: $this->bystanderToken)->assertOk();
    }

    private function reissueTokens(): void
    {
        $this->user->tokens()->delete();
        $this->tokens = [$this->issueToken($this->user->fresh(), 'customer', 'Phone one'), $this->issueToken($this->user->fresh(), 'customer', 'Phone two')];
    }
}
