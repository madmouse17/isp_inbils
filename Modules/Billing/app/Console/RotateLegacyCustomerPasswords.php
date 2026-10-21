<?php

namespace Modules\Billing\Console;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\CustomerService;
use Illuminate\Console\Command;
use Modules\Billing\Services\PortalCredentials;

/**
 * Deploy step of the customer portal. Customer logins created before random passwords have the customer's
 * phone number as their password and are flagged must_change_password. This replaces that password with a
 * random one and queues a set-password link, so only the owner of the mailbox can open the account. The flag
 * stays until the reset is completed, so a re-run only touches accounts that have not finished it yet.
 */
class RotateLegacyCustomerPasswords extends Command
{
    protected $signature = 'customers:rotate-legacy-passwords {--dry-run : Report what would change without writing or emailing}';

    protected $description = 'Replace the phone-number passwords of flagged customer logins and queue a set-password link for each';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $totals = ['rotated' => 0, 'links' => 0, 'no link (inactive or no email)' => 0];

        // Every company, active or not: a dormant company's logins must not keep a guessable password either.
        foreach (Company::query()->pluck('id') as $companyId) {
            CompanyService::runAs((int) $companyId, function () use ($companyId, $dryRun, &$totals): void {
                User::query()
                    ->where('company_id', $companyId)
                    ->where('must_change_password', true)
                    ->withPermissionIn('portal.access', (int) $companyId)
                    ->chunkById(100, function ($users) use ($dryRun, &$totals): void {
                        foreach ($users as $user) {
                            $linkable = $user->is_active && filled($user->email);
                            $totals['rotated']++;
                            $totals[$linkable ? 'links' : 'no link (inactive or no email)']++;

                            if ($dryRun) {
                                continue;
                            }

                            PortalCredentials::rotateLegacyPassword($user);

                            if ($linkable) {
                                CustomerService::sendAccessLink($user);
                            }
                        }
                    });
            });
        }

        $this->table(array_map('ucfirst', array_keys($totals)), [array_values($totals)]);
        $this->info($dryRun ? '[DRY RUN] Nothing was changed or sent.' : 'Legacy customer passwords rotated; links are queued, so a queue worker and mail must be running.');

        return self::SUCCESS;
    }
}
