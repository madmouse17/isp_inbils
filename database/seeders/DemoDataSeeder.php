<?php

namespace Database\Seeders;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Services\Core\CompanyService;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\BillingDemo;
use Database\Seeders\Demo\CustomerDemo;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\EvaluationDemo;
use Database\Seeders\Demo\FoundationDemo;
use Database\Seeders\Demo\InventoryDemo;
use Database\Seeders\Demo\LocationDemo;
use Database\Seeders\Demo\NetworkDemo;
use Database\Seeders\Demo\NotificationDemo;
use Database\Seeders\Demo\TicketDemo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Six months of a fibre ISP in Kota Bandung inside the demo company: staff and fleet, the POP/ODC/ODP tree,
 * warehouse stock, forty customers with installations, subscriptions, invoices and payments, tickets,
 * evaluations and notifications. Everything goes through the services that guard the rules (stock ledger,
 * billing, SPK, tickets, onboarding), acted out by the demo users in the order it would have happened.
 *
 * Needs RolePermissionSeeder and DemoUserSeeder first. Safe to run twice: the demo customer DEMO-C001
 * marks a finished run and the seeder writes nothing when it finds it.
 */
class DemoDataSeeder extends Seeder
{
    private const MARKER_CUSTOMER = 'DEMO-C001';

    public function run(): void
    {
        $company = Company::query()->where('code', 'DEMO')->first()
            ?? throw new RuntimeException('DemoDataSeeder seeds the demo company: run RolePermissionSeeder and DemoUserSeeder first.');

        $seeded = CompanyService::runAs((int) $company->id, fn () => Customer::query()->where('code', self::MARKER_CUSTOMER)->exists());

        if ($seeded) {
            $this->command?->info('Demo data is already present, nothing to seed.');

            return;
        }

        $testNow = Carbon::getTestNow();
        $user = Auth::user();
        $queue = config('queue.default');
        $mailer = config('mail.default');

        // Queued notifications and mail run inline and the mail goes nowhere: seeding needs no worker and sends nothing.
        config(['queue.default' => 'sync', 'mail.default' => 'array']);

        try {
            CompanyService::runAs((int) $company->id, fn () => DB::transaction(fn () => $this->seed($company)));
        } finally {
            Carbon::setTestNow($testNow);
            CarbonImmutable::setTestNow($testNow);
            config(['queue.default' => $queue, 'mail.default' => $mailer]);
            $user !== null ? Auth::setUser($user) : Auth::forgetGuards();
        }

        $this->command?->info('Demo data seeded: '.CompanyService::runAs((int) $company->id, fn () => Customer::query()->count()).' customers with six months of history.');
    }

    private function seed(Company $company): void
    {
        $c = new DemoContext($company);
        $c->travel($c->start->subDays(3));

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
    }
}
