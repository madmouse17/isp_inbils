<?php

namespace Tests\Feature\Platform;

use App\Events\CompanyCreated;
use App\Jobs\Platform\SeedTenantDemoDataJob;
use App\Models\Core\Company;
use App\Models\Role;
use App\Models\User;
use App\Notifications\Platform\TenantAdminInvitation;
use App\Services\Core\CompanyService;
use DateTimeZone;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * "Perusahaan baru": the platform operator provisions a tenant and its first admin in one step, through the same
 * service as the setup wizard, and the admin gets an Indonesian invitation to set a password.
 */
class TenantProvisioningTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'PT Nusa Net',
            'code' => 'NUSA',
            'timezone' => 'Asia/Makassar',
            'currency' => 'IDR',
            'admin_name' => 'Rina Pratama',
            'admin_email' => 'rina@nusanet.test',
            'catalog' => 'starter',
            ...$overrides,
        ];
    }

    private function nusa(): Company
    {
        return Company::query()->where('code', 'NUSA')->firstOrFail();
    }

    public function test_only_a_platform_admin_with_a_verified_second_factor_reaches_provisioning(): void
    {
        $tenantAdmin = $this->buildTenant('OLD', 'PT Lama')['admin'];
        $requests = [
            fn () => $this->get(route('platform.companies.create')),
            fn () => $this->post(route('platform.companies.store'), $this->payload()),
        ];

        foreach ($requests as $request) {
            $this->asTenantVisitor();
            $request()->assertRedirect(route('platform.login'));

            $this->actingAs($tenantAdmin);
            $request()->assertRedirect(route('platform.login'));

            $this->signInPlatformAdmin($this->operator, ['platform.two_factor_verified_at' => null]);
            $request()->assertRedirect(route('platform.login'));
        }

        $this->assertDatabaseMissing('companies', ['code' => 'NUSA']);
        $this->assertDatabaseMissing('users', ['email' => 'rina@nusanet.test']);

        $this->asOperator(false)->get(route('platform.companies.create'))->assertOk();
    }

    public function test_the_create_page_offers_the_defaults_the_timezones_and_the_currencies(): void
    {
        $this->asOperator(false)->get(route('platform.companies.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Companies/Create')
                ->where('defaults', ['timezone' => 'Asia/Jakarta', 'currency' => 'IDR'])
                ->where('currencies', ['IDR', 'USD', 'SGD', 'EUR'])
                ->where('timezones', DateTimeZone::listIdentifiers()));
    }

    public function test_provisioning_creates_the_company_its_admin_the_defaults_and_one_invitation(): void
    {
        Notification::fake();
        $other = $this->buildTenant('OLD', 'PT Lama');
        $usersBefore = User::query()->count();

        $response = $this->asOperator(false)->post(route('platform.companies.store'), $this->payload());

        $company = $this->nusa();
        $admin = User::query()->where('email', 'rina@nusanet.test')->firstOrFail();

        $response->assertRedirect(route('platform.companies.show', $company))->assertSessionHas('success');

        $this->assertSame('PT Nusa Net', $company->name);
        $this->assertSame('Asia/Makassar', $company->timezone);
        $this->assertSame('IDR', $company->currency);
        $this->assertTrue($company->is_active);
        $this->assertNull($company->parent_company_id);
        $this->assertNull($company->suspended_at);
        $this->assertSame('Rp', $company->settings['currency_symbol']);
        $this->assertSame(11, $company->settings['tax_ppn_rate']);

        $this->assertSame('Rina Pratama', $admin->name);
        $this->assertSame($company->id, $admin->company_id);
        $this->assertTrue($admin->is_active);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertSame(['admin'], DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->where('model_has_roles.model_id', $admin->id)->where('roles.company_id', $company->id)->pluck('roles.template_key')->all());
        $this->assertTrue(CompanyService::runAs($company->id, fn (): bool => $admin->can('roles.manage') && $admin->can('app.web')), 'he administers the company he was provisioned for');
        $this->assertSame(8, Role::forCompany($company->id)->whereNotNull('template_key')->count(), 'the company got a copy of every template');
        $this->assertTrue(Hash::isHashed($admin->getRawOriginal('password')), 'a random password, hashed: nobody knows it');
        $this->assertSame(1, DB::table('company_user')->where(['user_id' => $admin->id, 'company_id' => $company->id, 'is_default' => 1])->count());

        $this->assertSame($usersBefore + 1, User::query()->count(), 'only the first admin: the acting user of the seeder is that admin, no system-seed user');
        $this->assertDatabaseMissing('users', ['email' => "system-seed-{$company->id}@inbils.internal"]);
        $this->assertSame(4, DB::table('units')->where('company_id', $company->id)->count());
        $this->assertTrue(DB::table('ticket_categories')->where(['company_id' => $company->id, 'code' => 'no_internet'])->exists());
        $this->assertTrue(DB::table('locations')->where(['company_id' => $company->id, 'code' => 'POP-01'])->exists());
        $this->assertSame(0, DB::table('stocks')->where('company_id', $company->id)->count(), 'the starter catalog has no stock rows');
        $this->assertSame(0, DB::table('network_assets')->where('company_id', $company->id)->count(), 'and no sample assets');

        Notification::assertSentTo($admin, TenantAdminInvitation::class, fn (TenantAdminInvitation $mail, array $channels): bool => $channels === ['mail'] && $mail->companyId === $company->id);
        Notification::assertCount(1);

        $this->assertSame(1, User::query()->where('company_id', $other['root']->id)->where('email', $other['admin']->email)->count());
        $this->assertSame(0, DB::table('units')->where('company_id', $other['root']->id)->count(), 'the other tenant got no defaults of ours');
    }

    public function test_the_provisioning_is_audited_for_the_platform_without_the_admins_email(): void
    {
        $this->asOperator(false)->post(route('platform.companies.store'), $this->payload());

        $company = $this->nusa();
        $admin = User::query()->where('email', 'rina@nusanet.test')->firstOrFail();
        $row = Activity::query()->where('log_name', 'platform')->where('description', 'tenant.provisioned')->sole();

        $this->assertSame($this->operator->id, $row->causer_id);
        $this->assertSame($this->operator::class, $row->causer_type);
        $this->assertSame($company->id, $row->subject_id);
        $this->assertSame($company->id, (int) $row->company_id);
        $this->assertSame('NUSA', $row->properties['code']);
        $this->assertSame($admin->id, $row->properties['admin_user_id']);
        $this->assertFalse($row->properties['demo_data']);
        $this->assertSame('starter', $row->properties['catalog']);
        $this->assertStringNotContainsString('rina@nusanet.test', json_encode($row->properties));
        $this->assertSame(0, Activity::query()->where('log_name', 'support')->count(), 'there is no tenant yet to show a support entry to');
    }

    public function test_the_invited_admin_sets_a_password_from_the_mail_and_signs_in(): void
    {
        $this->asOperator(false)->post(route('platform.companies.store'), $this->payload())->assertRedirect();

        $mails = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $mails);
        $message = $mails->sole()->getOriginalMessage();
        $this->assertSame('Undangan admin PT Nusa Net di Inbils', $message->getSubject());
        $this->assertSame('rina@nusanet.test', $message->getTo()[0]->getAddress());
        $this->assertSame(1, preg_match('#/reset-password/([^?"]+)\?email=#', (string) $message->getHtmlBody(), $match));

        $admin = User::query()->where('email', 'rina@nusanet.test')->firstOrFail();
        $this->assertTrue(Password::broker()->tokenExists($admin, $match[1]));

        $this->asTenantVisitor()->post(route('password.store'), [
            'token' => $match[1],
            'email' => 'rina@nusanet.test',
            'password' => 'N3w-Secret-Pass!',
            'password_confirmation' => 'N3w-Secret-Pass!',
        ])->assertSessionHasNoErrors();

        $this->asTenantVisitor()->post('/login', ['email' => 'rina@nusanet.test', 'password' => 'N3w-Secret-Pass!'])
            ->assertRedirect(route('admin.dashboard', absolute: false));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_invitation_is_a_queued_indonesian_mail_whose_payload_holds_no_token(): void
    {
        $this->asOperator(false)->post(route('platform.companies.store'), $this->payload());
        $company = $this->nusa();
        $admin = User::query()->where('email', 'rina@nusanet.test')->firstOrFail();
        $invitation = new TenantAdminInvitation($company->id);

        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $invitation);
        $this->assertSame(['mail'], $invitation->via($admin));
        $this->assertStringNotContainsString('token', serialize($invitation), 'the reset token is minted at send time, never queued');

        $mail = $invitation->toMail($admin);
        $text = implode(' ', [$mail->subject, $mail->greeting, ...$mail->introLines, $mail->actionText, ...$mail->outroLines]);

        $this->assertSame('Halo Rina Pratama,', $mail->greeting);
        $this->assertStringContainsString('PT Nusa Net', $text);
        $this->assertStringContainsString('Atur kata sandi', $text);
        $this->assertStringContainsString('berlaku 60 menit', $text);
        $this->assertStringContainsString('mengirim ulang', $text, 'the copy names the resend fallback');
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/reset-password/', $mail->actionUrl);
    }

    public function test_a_failure_late_in_provisioning_rolls_back_everything_and_sends_nothing(): void
    {
        Storage::fake('public');
        Notification::fake();
        Queue::fake();
        Event::listen(CompanyCreated::class, function (): void {
            throw new RuntimeException('boom');
        });
        $companies = Company::query()->count();
        $memberships = DB::table('company_user')->count();
        $this->withoutExceptionHandling();

        try {
            $this->asOperator(false)->post(route('platform.companies.store'), $this->payload([
                'demo_data' => true,
                'logo' => UploadedFile::fake()->image('logo.png', 600, 300),
            ]));
            $this->fail('the failure must reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($companies, Company::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'rina@nusanet.test']);
        $this->assertSame($memberships, DB::table('company_user')->count());
        $this->assertSame(0, DB::table('media')->count(), 'no logo row of a company that never was');
        $this->assertSame(0, Activity::query()->where('description', 'tenant.provisioned')->count());
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_a_logo_is_stored_for_the_new_company(): void
    {
        Storage::fake('public');

        $this->asOperator(false)->post(route('platform.companies.store'), $this->payload([
            'logo' => UploadedFile::fake()->image('logo.png', 1200, 600),
        ]))->assertRedirect();

        $media = $this->nusa()->getFirstMedia('logo');
        $this->assertNotNull($media);
        $this->assertStringStartsWith('companies/'.$this->nusa()->id.'/company-logo/', $media->getPathRelativeToRoot());
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
    }

    public function test_a_duplicate_code_or_email_is_refused_and_nothing_is_created(): void
    {
        Notification::fake();
        Company::factory()->create(['code' => 'ACME']);
        User::factory()->create(['email' => 'taken@example.test']);
        $companies = Company::query()->count();
        $users = User::query()->count();

        foreach (['ACME', 'acme'] as $code) {
            $this->asOperator(false)->post(route('platform.companies.store'), $this->payload(['code' => $code]))->assertSessionHasErrors('code');
        }

        foreach (['taken@example.test', 'TAKEN@example.test'] as $email) {
            $this->asOperator(false)->post(route('platform.companies.store'), $this->payload(['admin_email' => $email]))->assertSessionHasErrors('admin_email');
        }

        $this->assertSame($companies, Company::query()->count());
        $this->assertSame($users, User::query()->count());
        Notification::assertNothingSent();
    }

    public function test_the_form_is_validated(): void
    {
        Notification::fake();
        $invalid = [
            ['name' => ''],
            ['name' => str_repeat('n', 256)],
            ['code' => ''],
            ['code' => '-lead'],
            ['code' => 'has space'],
            ['code' => 'a/b'],
            ['code' => str_repeat('c', 51)],
            ['timezone' => 'Mars/Base'],
            ['currency' => 'JPY'],
            ['admin_name' => ''],
            ['admin_email' => ''],
            ['admin_email' => 'not-an-email'],
            ['admin_email' => str_repeat('a', 250).'@x.test'],
            ['catalog' => ''],
            ['catalog' => 'full'],
            ['catalog' => 'demo'],
            ['demo_data' => 'maybe'],
            ['logo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
            ['logo' => UploadedFile::fake()->image('big.png')->size(10241)],
        ];

        foreach ($invalid as $change) {
            $this->asOperator(false)->post(route('platform.companies.store'), $this->payload($change))->assertSessionHasErrors(array_key_first($change));
        }

        $this->assertDatabaseMissing('companies', ['code' => 'NUSA']);
        Notification::assertNothingSent();

        foreach (['name', 'code', 'timezone', 'currency', 'admin_name', 'admin_email', 'catalog'] as $required) {
            $this->asOperator(false)->post(route('platform.companies.store'), Arr::except($this->payload(), $required))->assertSessionHasErrors($required);
        }

        // Codes the platform accepts: letters, digits, dash and underscore, starting with a letter or digit.
        foreach (['A', '9lives', 'ab-cd_ef', str_repeat('x', 50)] as $code) {
            $this->asOperator(false)->post(route('platform.companies.store'), $this->payload(['code' => $code, 'admin_email' => Str::slug($code).'@ok.test']))->assertSessionDoesntHaveErrors('code');
        }
    }

    public function test_sample_data_is_queued_only_when_asked_and_only_for_the_new_tenant(): void
    {
        Queue::fake();

        $this->asOperator(false)->post(route('platform.companies.store'), $this->payload(['demo_data' => false]))->assertRedirect();
        Queue::assertNotPushed(SeedTenantDemoDataJob::class);

        $this->asOperator(false)->post(route('platform.companies.store'), $this->payload([
            'code' => 'TRIAL',
            'admin_email' => 'trial@nusanet.test',
            'demo_data' => true,
        ]))->assertRedirect();

        $trial = Company::query()->where('code', 'TRIAL')->firstOrFail();
        $admin = User::query()->where('email', 'trial@nusanet.test')->firstOrFail();

        Queue::assertPushed(SeedTenantDemoDataJob::class, 1);
        Queue::assertPushed(SeedTenantDemoDataJob::class, fn (SeedTenantDemoDataJob $job): bool => $job->companyId === $trial->id
            && $job->adminUserId === $admin->id
            && $job->platformAdminId === $this->operator->id);
        $this->assertTrue(Activity::query()->where('description', 'tenant.provisioned')->where('subject_id', $trial->id)->sole()->properties['demo_data']);
    }
}
