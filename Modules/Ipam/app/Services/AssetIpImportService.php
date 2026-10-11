<?php

namespace Modules\Ipam\Services;

use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Ipam\Support\IpNotation;
use Modules\NetworkAsset\Models\NetworkAsset;

/**
 * One-off registration of the free-text ip_address and management_ip values that exist on network assets
 * (Q6a). Idempotent, and it never edits an asset: values it cannot register are only reported.
 */
class AssetIpImportService
{
    /** @var list<string> */
    private const FIELDS = ['ip_address', 'management_ip'];

    /**
     * @return array{
     *     imported: int,
     *     already: int,
     *     unparseable: list<array{asset: string, field: string, value: string}>,
     *     duplicates: list<array{asset: string, field: string, value: string, holder: string}>
     * }
     */
    public static function run(int $companyId, bool $dryRun): array
    {
        return CompanyService::runAs($companyId, function () use ($companyId, $dryRun): array {
            $report = ['imported' => 0, 'already' => 0, 'unparseable' => [], 'duplicates' => []];

            // comparable => the asset that owns the value in this run; a dry run writes no row, so it must remember.
            $claimed = [];

            $work = function () use (&$report, &$claimed, $dryRun): void {
                NetworkAsset::query()->chunkById(200, function (Collection $assets) use (&$report, &$claimed, $dryRun): void {
                    foreach ($assets as $asset) {
                        foreach (self::FIELDS as $field) {
                            self::importValue($asset, $field, $dryRun, $report, $claimed);
                        }
                    }
                });
            };

            $dryRun ? $work() : DB::transaction($work);

            if (! $dryRun && $report['imported'] > 0) {
                AuditService::log('ipam', 'asset_ips_imported', [
                    'imported' => $report['imported'],
                    'unparseable' => count($report['unparseable']),
                    'duplicates' => count($report['duplicates']),
                ], null, $companyId);
            }

            return $report;
        });
    }

    /**
     * @param  array{imported: int, already: int, unparseable: list<array<string, string>>, duplicates: list<array<string, string>>}  $report
     * @param  array<string, array{id: int, code: string}>  $claimed
     */
    private static function importValue(NetworkAsset $asset, string $field, bool $dryRun, array &$report, array &$claimed): void
    {
        $value = trim((string) $asset->getAttribute($field));

        if ($value === '') {
            return;
        }

        $row = ['asset' => $asset->code, 'field' => $field, 'value' => $value];
        $parsed = IpNotation::address($value);

        if ($parsed === null) {
            $report['unparseable'][] = $row;

            return;
        }

        $owner = $claimed[$parsed->comparable] ?? null;

        if ($owner !== null) {
            if ($owner['id'] !== $asset->id) {
                $report['duplicates'][] = $row + ['holder' => 'held by asset '.$owner['code']];
            }

            return;
        }

        $holder = IpAssignmentService::findHolder($parsed);

        if ($holder !== null && $holder->network_asset_id === $asset->id) {
            $report['already']++;
            $claimed[$parsed->comparable] = ['id' => $asset->id, 'code' => $asset->code];

            return;
        }

        if ($holder !== null) {
            $report['duplicates'][] = $row + ['holder' => IpAssignmentService::describeHolder($holder)];

            return;
        }

        $claimed[$parsed->comparable] = ['id' => $asset->id, 'code' => $asset->code];
        $report['imported']++;

        if (! $dryRun) {
            IpAssignmentService::registerAssetAddress($asset, $parsed);
        }
    }
}
