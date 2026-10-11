<?php

namespace App\Services\Core;

use App\Events\CompanyCreated;
use App\Models\Core\Company;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The company hierarchy: one level, a Head over its subsidiaries. Only this service writes
 * companies.parent_company_id; the database refuses deleting a Head that still has subsidiaries.
 */
class CompanyHierarchyService
{
    /**
     * Attaches the company under a Head, or detaches it with a null parent.
     *
     * @return Company the refreshed company
     */
    public function setParent(Company $company, ?Company $parent): Company
    {
        DB::transaction(function () use ($company, $parent): void {
            $locked = Company::query()
                ->whereKey(array_filter([$company->id, $parent?->id]))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $company = $locked[$company->id];
            $parent = $parent === null ? null : $locked[$parent->id];

            if ($parent !== null) {
                abort_if($company->is($parent), 422, 'A company cannot be a subsidiary of itself.');
                abort_if($parent->parent_company_id !== null, 422, 'A subsidiary cannot be a Head: only one level of subsidiaries is allowed.');
                abort_if($company->children()->exists(), 422, 'A company with subsidiaries cannot become a subsidiary.');
            }

            $company->forceFill(['parent_company_id' => $parent?->id])->save();

            AuditService::log('company', $parent === null ? 'subsidiary_detached' : 'subsidiary_attached', [
                'parent_company_id' => $parent?->id,
            ], $company);
        });

        return $company->refresh();
    }

    /**
     * Creates a subsidiary under a Head and gives it the defaults every new company gets (units,
     * ticket categories, SLA tiers, ...). Currency and timezone default to the Head's, so a group
     * consolidates unless someone chooses otherwise on purpose.
     *
     * @param  array{name: string, code: string, currency?: string|null, timezone?: string|null}  $data
     */
    public function createSubsidiary(Company $head, array $data): Company
    {
        $company = DB::transaction(function () use ($head, $data): Company {
            abort_if($head->parent_company_id !== null, 422, 'A subsidiary cannot be a Head: only one level of subsidiaries is allowed.');

            $currency = $data['currency'] ?? $head->currency;

            $company = Company::query()->create([
                'name' => $data['name'],
                'code' => $data['code'],
                'timezone' => $data['timezone'] ?? $head->timezone,
                'currency' => $currency,
                'settings' => SetupWizardService::defaultSettings(
                    $currency,
                    $head->settings['date_format'] ?? SettingService::get('default_date_format', 'd M Y'),
                    $head->settings['datetime_format'] ?? 'd M Y H:i',
                ),
                'is_active' => true,
            ]);
            $company->forceFill(['parent_company_id' => $head->id])->save();

            AuditService::log('company', 'subsidiary_created', ['parent_company_id' => $head->id], $company);

            return $company;
        });

        CompanyCreated::dispatch($company);

        return $company->refresh();
    }

    /**
     * The companies a Head consolidates: itself first, then its active subsidiaries.
     *
     * @return Collection<int, Company>
     */
    public function consolidationSet(Company $head): Collection
    {
        return $head->children()->where('is_active', true)->orderBy('name')->orderBy('id')->get()->prepend($head)->values();
    }
}
