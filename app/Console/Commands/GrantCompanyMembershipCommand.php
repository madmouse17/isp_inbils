<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsCompany;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyRoleService;
use Illuminate\Console\Command;

class GrantCompanyMembershipCommand extends Command
{
    use FindsCompany;

    protected $signature = 'inbils:company:grant-membership
        {user : Email of the user}
        {company : Company code or id}';

    protected $description = 'Let a user act in another company. The home company is unchanged; roles are per company, so he gets the copy of every template role he holds at home.';

    public function handle(CompanyMembershipService $memberships, CompanyRoleService $roles): int
    {
        $user = User::query()->where('email', (string) $this->argument('user'))->first();
        $company = $this->findCompany((string) $this->argument('company'));

        if ($user === null || $company === null) {
            $this->error($user === null ? 'User not found.' : 'Company not found.');

            return self::FAILURE;
        }

        $home = $user->company;

        // A user without a home company gets this one as their home.
        $memberships->grant($user, $company, $home === null);

        $this->info("{$user->email} is now a member of {$company->name}.");

        if ($home?->is($company)) {
            return self::SUCCESS;
        }

        // A copy wider than what he holds at home is not given: it is left for a person to give.
        ['granted' => $granted, 'by_hand' => $byHand] = $home === null
            ? ['granted' => [], 'by_hand' => []]
            : $roles->mirrorTemplateRoles($user, $home, $company);
        $this->line($granted === []
            ? "{$user->email} holds no role in {$company->name}: assign roles in Pengguna."
            : "{$user->email} was given in {$company->name}: ".implode(', ', $granted).'.');

        if ($byHand !== []) {
            $this->line("{$user->email} was not given, wider than what he holds at home: assign in Pengguna: ".implode(', ', $byHand).'.');
        }

        return self::SUCCESS;
    }
}
