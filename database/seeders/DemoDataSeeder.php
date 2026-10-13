<?php

namespace Database\Seeders;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\NumberSequence;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use Carbon\CarbonImmutable;
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
use RuntimeException;

/**
 * Six months of a fibre ISP in Kota Bandung inside the demo company: staff and fleet, the POP/ODC/ODP tree,
 * warehouse stock, forty customers with installations, subscriptions, invoices and payments, tickets,
 * evaluations and notifications, then the address plan and fibre tree (IPAM/ODN), NAS routers with network
 * accounts, and WhatsApp consent with a delivery log. Everything goes through the services that guard the rules
 * (stock ledger, billing, SPK, tickets, onboarding), acted out by the demo users in the order it would have happened.
 *
 * Needs RolePermissionSeeder and DemoUserSeeder first. Safe to run twice: the demo customer DEMO-C001 marks a
 * finished run. On a company seeded before the IPAM/ODN, NAS and WhatsApp parts existed, a run adds just those
 * parts; each skips what it already wrote.
 */
class DemoDataSeeder extends Seeder
{
    private const MARKER_CUSTOMER = 'DEMO-C001';

    public function run(): void
    {
        $company = Company::query()->where('code', 'DEMO')->first()
            ?? throw new RuntimeException('DemoDataSeeder seeds the demo company: run RolePermissionSeeder and DemoUserSeeder first.');

        $seeded = CompanyService::runAs((int) $company->id, fn () => Customer::query()->where('code', self::MARKER_CUSTOMER)->exists());

        $testNow = Carbon::getTestNow();
        $user = Auth::user();
        $queue = config('queue.default');
        $mailer = config('mail.default');

        // Queued notifications and mail run inline and the mail goes nowhere: seeding needs no worker and sends nothing.
        config(['queue.default' => 'sync', 'mail.default' => 'array']);

        try {
            CompanyService::runAs((int) $company->id, fn () => DB::transaction(fn () => $seeded ? $this->seedNetworkLanes($company) : $this->seed($company)));
        } finally {
            Carbon::setTestNow($testNow);
            CarbonImmutable::setTestNow($testNow);
            config(['queue.default' => $queue, 'mail.default' => $mailer]);
            $user !== null ? Auth::setUser($user) : Auth::forgetGuards();
        }

        $this->command?->info($seeded
            ? 'Demo data was already present: the IPAM/ODN, NAS and WhatsApp parts are up to date.'
            : 'Demo data seeded: '.CompanyService::runAs((int) $company->id, fn () => Customer::query()->count()).' customers with six months of history.');
    }

    private function seed(Company $company): void
    {
        $c = new DemoContext($company);
        $c->travel($c->start->subDays(3));

        // The demo customers carry fixed codes (DEMO-C001...) that the onboarding action must accept.
        NumberSequenceService::ensureAll((int) $company->id);
        NumberSequence::forCompany((int) $company->id)->where('entity_type', 'customer')->update(['allow_manual' => true]);

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

        // The lanes read the finished state: drops and addresses only go to subscriptions that ended up in service.
        $c->travel($c->now->subMinute());
        $this->seedLanes($c);
    }

    /** The parts a company seeded before they existed still lacks. */
    private function seedNetworkLanes(Company $company): void
    {
        $c = new DemoContext($company);

        foreach (['admin', 'manager'] as $key) {
            $c->addUser($key, User::query()->where('email', "{$key}@demo.inbils.test")->firstOrFail());
        }

        $this->seedLanes($c);
    }

    private function seedLanes(DemoContext $c): void
    {
        IpamOdnDemo::seed($c);
        NetworkAccessDemo::seed($c);
        WhatsAppDemo::seed($c);
    }
}
