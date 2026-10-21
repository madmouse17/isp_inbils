<?php

namespace Database\Seeders;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\NumberSequence;
use App\Models\User;
use App\Services\Core\CompanyRoleService;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\Demo\BillingDemo;
use Database\Seeders\Demo\CustomerDemo;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\EvaluationDemo;
use Database\Seeders\Demo\FoundationDemo;
use Database\Seeders\Demo\InventoryDemo;
use Database\Seeders\Demo\IpamOdnDemo;
use Database\Seeders\Demo\LocationDemo;
use Database\Seeders\Demo\NetworkAccessDemo;
use Database\Seeders\Demo\NetworkDemo;
use Database\Seeders\Demo\NotificationDemo;
use Database\Seeders\Demo\TicketDemo;
use Database\Seeders\Demo\WhatsAppDemo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Six months of a fibre ISP in Kota Bandung: staff and fleet, the POP/ODC/ODP tree, warehouse stock, forty customers
 * with installations, subscriptions, invoices and payments, tickets, evaluations and notifications, then the address
 * plan and fibre tree (IPAM/ODN), and for the DEMO company NAS routers with network accounts and WhatsApp consent with
 * a delivery log. Everything goes through the services that guard the rules (stock ledger, billing, SPK, tickets,
 * onboarding), acted out by the demo users in the order it would have happened.
 *
 * run() fills the DEMO company: needs RolePermissionSeeder and DemoUserSeeder first and uses their fixed logins. Safe
 * to run twice: the demo customer DEMO-C001 marks a finished run. On a company seeded before the IPAM/ODN, NAS and
 * WhatsApp parts existed, a run adds just those parts; each skips what it already wrote.
 *
 * seedFor() fills a company a platform admin provisioned, as a trial: the real admin acts, four more logins are made
 * with random credentials nobody is told and addresses that carry the company id, and nothing that could leave the
 * company is written (no RADIUS router or account, no WhatsApp consent or message, no profile text, no mobile number).
 */
class DemoDataSeeder extends Seeder
{
    private const DEMO_COMPANY = 'DEMO';

    private const MARKER_CUSTOMER = 'DEMO-C001';

    /** What the story would mix with: one real row of any of these and the company is no blank slate. */
    private const OPERATING_TABLES = ['customers', 'service_subscriptions', 'work_orders', 'invoices', 'tickets'];

    public function run(): void
    {
        $company = Company::query()->where('code', self::DEMO_COMPANY)->first()
            ?? throw new RuntimeException('DemoDataSeeder seeds the demo company: run RolePermissionSeeder and DemoUserSeeder first.');

        $seeded = CompanyService::runAs((int) $company->id, fn () => $this->hasMarker($company));

        $this->inside($company, fn () => $seeded ? $this->topUp(new DemoContext($company)) : $this->seed(new DemoContext($company)));

        $this->command?->info($seeded
            ? 'Demo data was already present: the IPAM/ODN, NAS and WhatsApp parts are up to date.'
            : 'Demo data seeded: '.CompanyService::runAs((int) $company->id, fn () => Customer::query()->count()).' customers with six months of history.');
    }

    /**
     * Fills a company with the sample ISP as a trial. $admin is the company's real admin: homed there and an
     * administrator of it (CompanyRoleService). Refuses a company that already has customers, subscriptions, jobs, invoices or tickets, unless an
     * earlier run marked it (then only the idempotent parts are topped up). All or nothing, in one transaction.
     *
     * The story stands on the stock rows and sample assets that the starter catalog leaves out, so the company's catalog
     * goes up to the demo one first, inside the same transaction. A company that has them keeps them and gets no second
     * copy, so every caller (the queued job, the tests) can hand over a company on any catalog.
     */
    public function seedFor(Company $company, User $admin): void
    {
        if ((int) $admin->company_id !== (int) $company->id || ! app(CompanyRoleService::class)->isAdministrator($admin, (int) $company->id)) {
            throw new InvalidArgumentException("Sample data for {$company->code} needs the admin of that company; user {$admin->getKey()} is not one.");
        }

        DB::transaction(function () use ($company, $admin): void {
            $seeded = $this->hasMarker($company);

            if (! $seeded && $this->hasOperatingData($company)) {
                throw new RuntimeException("Company {$company->code} already has customers, jobs or invoices: sample data only goes into an empty company.");
            }

            // Before inside(): runFor resets the company scope it finds, so it must not run inside the one inside() opens.
            app(CompanySeeder::class)->runFor($company, CompanySeeder::CATALOG_DEMO);

            $this->inside($company, function () use ($company, $admin, $seeded): void {
                $c = new DemoContext($company, trial: true);
                $c->addUser('admin', $admin);

                $seeded ? $this->topUp($c) : $this->seed($c);
            });
        });
    }

    /**
     * Runs $work inside the company's system scope and one transaction. Queued notifications and mail run inline and
     * the mail goes nowhere: seeding needs no worker and sends nothing. The seeding clock, the signed-in user and
     * both settings are put back whatever happens.
     */
    private function inside(Company $company, Closure $work): void
    {
        $testNow = Carbon::getTestNow();
        $user = Auth::user();
        $queue = config('queue.default');
        $mailer = config('mail.default');

        config(['queue.default' => 'sync', 'mail.default' => 'array']);

        try {
            CompanyService::runAs((int) $company->id, fn () => DB::transaction($work));
        } finally {
            Carbon::setTestNow($testNow);
            CarbonImmutable::setTestNow($testNow);
            config(['queue.default' => $queue, 'mail.default' => $mailer]);
            $user !== null ? Auth::setUser($user) : Auth::forgetGuards();
        }
    }

    private function hasMarker(Company $company): bool
    {
        return DB::table('customers')->where('company_id', $company->id)->where('code', self::MARKER_CUSTOMER)->exists();
    }

    private function hasOperatingData(Company $company): bool
    {
        foreach (self::OPERATING_TABLES as $table) {
            if (DB::table($table)->where('company_id', $company->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function seed(DemoContext $c): void
    {
        $c->travel($c->start->subDays(3));

        // The sample customers carry fixed codes (DEMO-C001...) that the onboarding action must accept. A trial company
        // gets the switch back the way it found it; the DEMO company keeps it on.
        NumberSequenceService::ensureAll($c->companyId());
        $codes = fn () => NumberSequence::forCompany($c->companyId())->where('entity_type', 'customer');
        $manualBefore = $codes()->value('allow_manual');
        $codes()->update(['allow_manual' => true]);

        FoundationDemo::seed($c);
        LocationDemo::seed($c);
        InventoryDemo::seedMaster($c);
        NetworkDemo::seedCore($c);

        InventoryDemo::plan($c);
        NetworkDemo::planCore($c);
        CustomerDemo::plan($c);
        TicketDemo::plan($c);
        BillingDemo::plan($c);

        $c->timeline->run();

        EvaluationDemo::seed($c);
        NotificationDemo::seed($c);

        if ($c->trial) {
            $codes()->update(['allow_manual' => (bool) $manualBefore]);
        }

        // The lanes read the finished state: drops and addresses only go to subscriptions that ended up in service.
        $c->travel($c->now->subMinute());
        $this->seedLanes($c);
    }

    /** The parts a company seeded before they existed still lacks. */
    private function topUp(DemoContext $c): void
    {
        if ($c->trial) {
            // The admin is registered; the sample manager acts, or the admin when somebody deleted that login.
            $c->addUser('manager', User::query()->where('email', $c->email('manager'))->first() ?? $c->user('admin'));
        } else {
            foreach (['admin', 'manager'] as $key) {
                $c->addUser($key, User::query()->where('email', $c->email($key))->firstOrFail());
            }
        }

        $this->seedLanes($c);
    }

    private function seedLanes(DemoContext $c): void
    {
        IpamOdnDemo::seed($c);

        // A RADIUS router and account can be fed to a live server, and WhatsApp consent can be acted on later: only the DEMO company has them.
        if (! $c->trial) {
            NetworkAccessDemo::seed($c);
            WhatsAppDemo::seed($c);
        }
    }
}
