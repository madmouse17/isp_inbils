<?php

namespace App\Queries\Admin;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Modules\Service\Models\ServicePackage;

/**
 * The first-run checklist on the dashboard: what a new company still has to set up. Every step is ticked from stored
 * data, never from a flag the admin sets by hand, and links to the page that finishes it. Only whoever administers the
 * company sees it, and only the steps that person may open and do, as with the admin module links: the card never lists
 * a link that answers 403. It is null once nothing is left.
 */
final class OnboardingChecklistQuery
{
    /** @var array<string, array{label: string, route: string, fragment?: string}> in the order a company is usually set up */
    private const STEPS = [
        'profile' => ['label' => 'Lengkapi profil perusahaan', 'route' => 'admin.company.profile.edit'],
        'map' => ['label' => 'Atur wilayah peta', 'route' => 'admin.company.settings.edit', 'fragment' => 'peta'],
        'pop' => ['label' => 'Tambah POP', 'route' => 'admin.locations.index'],
        'package' => ['label' => 'Atur paket layanan', 'route' => 'admin.service-packages.index'],
        'team' => ['label' => 'Tambahkan pengguna', 'route' => 'admin.users.index'],
        'customer' => ['label' => 'Daftarkan pelanggan pertama', 'route' => 'admin.customers.create'],
        'billing' => ['label' => 'Atur penagihan otomatis', 'route' => 'admin.company.settings.edit', 'fragment' => 'tagihan'],
    ];

    /**
     * @return array{steps: list<array{key: string, label: string, done: bool, href: string}>, done: int, total: int}|null
     */
    public static function for(User $user): ?array
    {
        if (! $user->can('company.manage')) {
            return null;
        }

        $companyId = CompanyService::idFor($user);
        abort_if($companyId === null, 403, 'Konteks perusahaan diperlukan.');

        $company = Company::query()->findOrFail($companyId);
        $steps = [];

        foreach (self::STEPS as $key => $step) {
            if (! self::canDo($user, $key)) {
                continue;
            }

            $steps[] = [
                'key' => $key,
                'label' => $step['label'],
                'done' => self::isDone($key, $company),
                'href' => route($step['route']).(isset($step['fragment']) ? '#'.$step['fragment'] : ''),
            ];
        }

        $done = count(array_filter($steps, fn (array $step): bool => $step['done']));

        return $done === count($steps) ? null : ['steps' => $steps, 'done' => $done, 'total' => count($steps)];
    }

    /** The three company steps need nothing beyond the card's own gate; the others need what their page needs, and what it asks. */
    private static function canDo(User $user, string $step): bool
    {
        return match ($step) {
            'pop' => $user->can('viewAny', Location::class) && $user->can('create', Location::class),
            'package' => $user->can('viewAny', ServicePackage::class) && $user->can('create', ServicePackage::class),
            'team' => $user->can('viewAny', User::class) && $user->can('create', User::class),
            // The registration page refuses without all four (CustomerController::canOnboard).
            'customer' => $user->can('create', Customer::class)
                && $user->can('customer.address.manage')
                && $user->can('customer.subscription.manage')
                && $user->can('spk.create'),
            default => true,
        };
    }

    private static function isDone(string $step, Company $company): bool
    {
        $settings = $company->settings ?? [];

        return match ($step) {
            'profile' => filled($company->address) && filled($company->phone),
            'map' => filled($settings['map_city_code'] ?? null) || filled($settings['map_lat'] ?? null),
            'pop' => Location::query()->forCompany($company->id)->ofType('pop')->active()->exists(),
            'package' => ServicePackage::query()->forCompany($company->id)->where('is_active', true)->exists(),
            'team' => self::hasTeammate($company->id),
            'customer' => Customer::query()->forCompany($company->id)->exists(),
            'billing' => filled($settings['billing_defaults_confirmed_at'] ?? null),
            default => throw new InvalidArgumentException("Unknown onboarding step [{$step}]."),
        };
    }

    /** Two or more active staff logins of the company: a customer's app account is not a teammate. */
    private static function hasTeammate(int $companyId): bool
    {
        return User::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNotExists(fn (QueryBuilder $customer) => $customer->from('customers')->whereColumn('customers.user_id', 'users.id'))
            ->count() > 1;
    }
}
