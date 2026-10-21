<?php

namespace Tests\Feature\Platform;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\NumberSequence;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\WhatsApp\PhoneNumber;
use App\Support\Platform\TenantTableRegistry;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * DemoDataSeeder::seedFor fills a company a platform admin just provisioned with the sample ISP, under the rules of
 * a production tenant: no fixed login, no number or address that could be delivered to, nothing that reaches RADIUS
 * or WhatsApp, nothing outside the one company, and the process left as it was found.
 */
class DemoDataTrialTest extends PurgeTestCase
{
    /** The tables that tell the story; their counts must be the same for every trial. */
    private const STORY = ['customers', 'service_subscriptions', 'work_orders', 'invoices', 'payments', 'tickets', 'stock_movements', 'ip_addresses', 'odn_ports', 'employee_profiles', 'network_assets', 'locations'];

    public function test_two_new_tenants_get_safe_sample_data_that_stays_apart(): void
    {
        [$alpha, $alphaAdmin] = $this->newTenant('ALP');
        [$beta, $betaAdmin] = $this->newTenant('BET');
        $profile = $alpha->only(['address', 'phone', 'email', 'website', 'settings']);
        $adminHash = $alphaAdmin->password;

        app(DemoDataSeeder::class)->seedFor($alpha, $alphaAdmin);

        $this->assertTrialIsSafe($alpha, $alphaAdmin);
        $this->assertSame($profile, $alpha->refresh()->only(['address', 'phone', 'email', 'website', 'settings']), 'the profile and settings the admin gave the company are not overwritten');
        $this->assertSame($adminHash, $alphaAdmin->refresh()->password, 'the real admin keeps the credential they were given');
        $this->assertSame(0, $this->story($beta)['customers'], 'the other tenant got nothing');

        $story = $this->story($alpha);
        $this->assertSame(40, $story['customers']);

        foreach (['service_subscriptions', 'work_orders', 'invoices', 'payments', 'tickets', 'stock_movements', 'ip_addresses', 'odn_ports', 'network_assets'] as $table) {
            $this->assertGreaterThan(0, $story[$table], "the sample {$table} are missing");
        }

        foreach (['ip_prefixes', 'odn_splitters', 'employee_evaluations', 'vlans'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->where('company_id', $alpha->id)->count(), "the sample {$table} are missing");
        }

        $all = $this->snapshot($alpha);

        app(DemoDataSeeder::class)->seedFor($beta, $betaAdmin);

        $this->assertTrialIsSafe($beta, $betaAdmin);
        $this->assertSame($all, $this->snapshot($alpha), 'seeding the second tenant changed the first one');
        $this->assertSame($story, $this->story($beta), 'two trials tell the same story');
        $this->assertSame(0, $this->rowsOutside([$alpha->id, $beta->id]), 'a row of a third company appeared');

        app(DemoDataSeeder::class)->seedFor($alpha, $alphaAdmin);

        $this->assertSame($all, $this->snapshot($alpha), 'a second run on a seeded company adds nothing');
    }

    public function test_it_leaves_the_clock_config_login_and_number_sequence_as_it_found_them(): void
    {
        [$company, $admin] = $this->newTenant('ALP');
        $signedIn = User::factory()->create();
        $this->actingAs($signedIn);
        config(['queue.default' => 'database', 'mail.default' => 'log']);

        app(DemoDataSeeder::class)->seedFor($company, $admin);

        $this->assertNull(Carbon::getTestNow());
        $this->assertSame('database', config('queue.default'));
        $this->assertSame('log', config('mail.default'));
        $this->assertTrue($signedIn->is(Auth::user()));
        $this->assertFalse((bool) NumberSequence::query()->forCompany($company->id)->where('entity_type', 'customer')->value('allow_manual'), 'the manual customer code switch is turned back off');
    }

    public function test_a_failure_rolls_everything_back_and_still_restores_the_process(): void
    {
        [$company, $admin] = $this->newTenant('ALP');
        $this->companyRoles($company)['technician']->delete();
        app()['cache']->forget('spatie.permission.cache');
        config(['queue.default' => 'database', 'mail.default' => 'log']);
        $before = $this->snapshot($company);

        try {
            app(DemoDataSeeder::class)->seedFor($company, $admin);
            $this->fail('the missing role must stop the run');
        } catch (Throwable $e) {
            $this->assertStringContainsString('technician', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot($company), 'nothing of the half-done run stays');
        $this->assertNull(Carbon::getTestNow());
        $this->assertSame('database', config('queue.default'));
        $this->assertSame('log', config('mail.default'));
        $this->assertNull(Auth::user());
    }

    public function test_it_refuses_a_company_that_already_works_for_customers(): void
    {
        [$company, $admin] = $this->newTenant('ALP');
        Customer::factory()->create(['company_id' => $company->id, 'code' => 'REAL-1']);
        $before = $this->snapshot($company);

        try {
            app(DemoDataSeeder::class)->seedFor($company, $admin);
            $this->fail('a company with customers must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ALP', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot($company), 'a refused run writes nothing');
    }

    public function test_it_refuses_an_admin_who_is_not_the_admin_of_that_company(): void
    {
        [$alpha] = $this->newTenant('ALP');
        [, $betaAdmin] = $this->newTenant('BET');
        $manager = User::factory()->create(['company_id' => $alpha->id]);
        $this->grantRole($manager, 'manager');
        $before = $this->snapshot($alpha);

        foreach ([$betaAdmin, $manager] as $wrong) {
            try {
                app(DemoDataSeeder::class)->seedFor($alpha, $wrong);
                $this->fail('only the admin of the company may be named');
            } catch (InvalidArgumentException) {
                $this->assertSame($before, $this->snapshot($alpha));
            }
        }
    }

    private function assertTrialIsSafe(Company $company, User $admin): void
    {
        $id = $company->id;
        $users = User::query()->where('company_id', $id)->where('id', '!=', $admin->id)->get();
        $customers = DB::table('customers')->where('company_id', $id)->count();

        $this->assertCount(10 + $customers, $users, 'four sample logins, six more staff and one portal login per customer');
        // The roles are the company's: nobody is signed in here, so the company is named.
        $this->assertSame(['Admin'], CompanyService::runAs($id, fn (): array => $admin->refresh()->getRoleNames()->all()), 'the real admin keeps the admin role and gets no other');
        $this->assertSame(
            ['customer', 'manager', 'staff', 'technician'],
            $users->map(fn (User $user): string => Str::before($user->email, '@'))->intersect(['manager', 'staff', 'technician', 'customer'])->sort()->values()->all(),
        );

        foreach ($users as $user) {
            $this->assertMatchesRegularExpression('/^[a-z0-9.\-]+@(pelanggan\.)?c'.$id.'\.demo\.inbils\.test$/', $user->email, 'the address names the company and ends in the reserved .test');
            $this->assertFalse(Hash::check('password', $user->password), "{$user->email} must not take the well-known demo credential");
            $this->assertFalse(Hash::check(DemoUserSeeder::PASSWORD, $user->password));
            $this->assertNull($user->two_factor_secret);
        }

        $this->assertSame($users->count(), $users->pluck('password')->unique()->count(), 'every sample login has its own random credential');
        $this->assertSame(0, User::query()->where('email', 'like', '%@demo.inbils.test')->count(), 'no address of the shared demo company');

        $this->assertSame(0, DB::table('nas_devices')->where('company_id', $id)->count());
        $this->assertSame(0, DB::table('network_accounts')->where('company_id', $id)->count());
        $this->assertSame(0, DB::table('customers')->where('company_id', $id)->whereNotNull('whatsapp_opt_in_at')->count(), 'no consent was recorded for anybody');
        $this->assertSame(0, DB::table('customers')->where('company_id', $id)->whereNotNull('whatsapp_opt_out_at')->count());
        $this->assertSame(0, DB::table('whatsapp_messages')->where('company_id', $id)->where('status', '!=', 'skipped')->count(), 'nothing was handed to WhatsApp');
        $this->assertSame(0, DB::table('whatsapp_messages')->where('company_id', $id)->whereNotNull('provider_message_id')->count());
        $this->assertFalse((bool) ($company->refresh()->settings['whatsapp_enabled'] ?? false), 'the WhatsApp switch stays off');

        foreach (['customers', 'customer_contacts', 'employee_profiles'] as $table) {
            $phones = DB::table($table)->where('company_id', $id)->whereNotNull('phone')->pluck('phone');
            $this->assertNotEmpty($phones, "{$table} has no phone to check");

            foreach ($phones as $phone) {
                $this->assertNull(PhoneNumber::normalizeIndonesianMobile($phone), "{$table}.phone {$phone} is a mobile number WhatsApp could reach");
            }
        }

        $this->assertNull($company->address);
        $this->assertNull($company->phone);
        $this->assertArrayNotHasKey('bank_account_info', $company->settings ?? []);
    }

    /** @return array<string, int> */
    private function story(Company $company): array
    {
        return collect(self::STORY)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->where('company_id', $company->id)->count()])->all();
    }

    /** @return array<string, int> rows per tenant table of one company */
    private function snapshot(Company $company): array
    {
        return collect(app(TenantTableRegistry::class)->tables())
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->where('company_id', $company->id)->count()])
            ->all();
    }

    /** @param  list<int>  $ids */
    private function rowsOutside(array $ids): int
    {
        return collect(app(TenantTableRegistry::class)->tables())->sum(fn (string $table): int => DB::table($table)->whereNotIn('company_id', $ids)->count());
    }
}
