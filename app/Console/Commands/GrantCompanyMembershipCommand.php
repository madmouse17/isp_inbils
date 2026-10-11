<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsCompany;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use Illuminate\Console\Command;

class GrantCompanyMembershipCommand extends Command
{
    use FindsCompany;

    protected $signature = 'inbils:company:grant-membership
        {user : Email of the user}
        {company : Company code or id}';

    protected $description = 'Let a user act in another company. Roles stay global; the home company is unchanged.';

    public function handle(CompanyMembershipService $memberships): int
    {
        $user = User::query()->where('email', (string) $this->argument('user'))->first();
        $company = $this->findCompany((string) $this->argument('company'));

        if ($user === null || $company === null) {
            $this->error($user === null ? 'User not found.' : 'Company not found.');

            return self::FAILURE;
        }

        // A user without a home company gets this one as their home.
        $memberships->grant($user, $company, $user->company_id === null);

        $this->info("{$user->email} is now a member of {$company->name}.");

        return self::SUCCESS;
    }
}
