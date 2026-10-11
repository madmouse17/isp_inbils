<?php

namespace Modules\Ipam\Console;

use App\Models\Core\Company;
use Illuminate\Console\Command;
use Modules\Ipam\Services\AssetIpImportService;

class ImportAssetIpsCommand extends Command
{
    protected $signature = 'ipam:import-asset-ips
        {--company= : Company id (default: every company)}
        {--dry-run : Report what would be imported and write nothing}';

    protected $description = 'Register the IP addresses already typed on network assets in the IPAM register (never edits assets)';

    public function handle(): int
    {
        $option = $this->option('company');

        if ($option !== null && ! ctype_digit((string) $option)) {
            $this->error('--company must be a company id.');

            return self::FAILURE;
        }

        $companies = Company::query()
            ->when($option !== null, fn ($query) => $query->whereKey((int) $option))
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($companies->isEmpty()) {
            $this->error('No company matches.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        foreach ($companies as $company) {
            $report = AssetIpImportService::run($company->id, $dryRun);

            $this->info(sprintf(
                '%scompany %d (%s): imported %d, already registered %d, unparseable %d, duplicates %d',
                $dryRun ? '[dry run] ' : '',
                $company->id,
                $company->name,
                $report['imported'],
                $report['already'],
                count($report['unparseable']),
                count($report['duplicates']),
            ));

            if ($report['unparseable'] !== []) {
                $this->warn('Unparseable values:');
                $this->table(['Asset', 'Field', 'Value'], $report['unparseable']);
            }

            if ($report['duplicates'] !== []) {
                $this->warn('Duplicates (not imported):');
                $this->table(['Asset', 'Field', 'Value', 'Already'], $report['duplicates']);
            }
        }

        return self::SUCCESS;
    }
}
