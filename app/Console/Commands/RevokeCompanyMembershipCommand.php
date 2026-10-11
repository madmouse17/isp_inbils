<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsCompany;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RevokeCompanyMembershipCommand extends Command
{
    use FindsCompany;

    protected $signature = 'inbils:company:revoke-membership
        {user : Email of the user}
        {company : Company code or id}';

    protected $description = 'Take away a granted membership. The home company cannot be revoked.';

    public function handle(CompanyMembershipService $memberships): int
    {
        $user = User::query()->where('email', (string) $this->argument('user'))->first();
        $company = $this->findCompany((string) $this->argument('company'));

        if ($user === null || $company === null) {
            $this->error($user === null ? 'User not found.' : 'Company not found.');

            return self::FAILURE;
        }

        try {
            $memberships->revoke($user, $company);
        } catch (HttpExceptionInterface $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$user->email} is no longer a member of {$company->name}.");

        return self::SUCCESS;
    }
}
