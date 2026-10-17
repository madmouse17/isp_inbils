<?php

namespace App\Console\Commands\Platform;

use App\Models\Core\Company;
use App\Support\Platform\TenantPurger;
use Illuminate\Console\Command;
use Throwable;

/**
 * The only way a tenant is ever deleted: a person at the server console, a tenant that has been suspended for 30 days
 * and exported, and the company code typed once more. No route, no flag that skips the question.
 */
class PurgeCompanyCommand extends Command
{
    protected $signature = 'inbils:platform:purge-company {code : The exact code of the tenant (its Head company), case-sensitive}';

    protected $description = 'Delete a suspended tenant (company, subsidiaries, users, data and files) for good. Interactive: the code must be typed again.';

    public function handle(TenantPurger $purger): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('This command asks you to type the company code, so it cannot run with --no-interaction or without a terminal. Nothing was deleted.');

            return self::FAILURE;
        }

        $code = (string) $this->argument('code');
        $company = Company::query()->where('code', $code)->first();

        if ($company === null || $company->code !== $code) {
            $this->error("No company has the code [{$code}] (codes are case-sensitive). Nothing was deleted.");

            return self::FAILURE;
        }

        if (($blockers = $purger->blockers($company)) !== []) {
            $this->error("Company {$code} may not be purged:");

            foreach ($blockers as $blocker) {
                $this->line(" - {$blocker}");
            }

            return self::FAILURE;
        }

        $plan = $purger->plan($company);

        $this->line("Tenant [{$code}] {$company->name}: company ids ".implode(', ', $plan['company_ids']).", {$plan['users']} users, {$plan['media']} media files, {$plan['rows']} rows in all.");
        $this->table(['Table', 'Rows'], collect($plan['tables'] + $plan['pivots'])->map(fn (int $rows, string $table): array => [$table, $rows])->values()->all());
        $this->warn('This deletes all of it, and the files of the tenant on every disk. There is no undo; the only way back is a backup.');

        if ((string) $this->ask("Type the company code [{$code}] to delete the tenant for good") !== $code) {
            $this->error('The code does not match. Nothing was deleted.');

            return self::FAILURE;
        }

        try {
            $left = $purger->purge($company);
        } catch (Throwable $e) {
            $this->error('The purge failed and the database was left unchanged: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tenant [{$code}] was deleted.");

        if ($left !== []) {
            $this->error('The database is clean, but these files could not be deleted. Remove them by hand (disk:path):');

            foreach ($left as $path) {
                $this->line(" - {$path}");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
