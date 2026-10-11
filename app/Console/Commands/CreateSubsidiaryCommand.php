<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsCompany;
use App\Models\User;
use App\Services\Core\CompanyHierarchyService;
use App\Services\Core\CompanyMembershipService;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CreateSubsidiaryCommand extends Command
{
    use FindsCompany;

    protected $signature = 'inbils:company:create-subsidiary
        {head : Head company code or id}
        {name : Name of the subsidiary}
        {code : Unique code of the subsidiary}
        {--currency= : IDR, USD, SGD or EUR; defaults to the Head currency}
        {--timezone= : Defaults to the Head timezone}
        {--member=* : Email of an existing user to add as a member (repeatable)}';

    protected $description = 'Create a subsidiary company under a Head company, with the defaults of every new company.';

    public function handle(CompanyHierarchyService $hierarchy, CompanyMembershipService $memberships): int
    {
        $head = $this->findCompany((string) $this->argument('head'));

        if ($head === null) {
            $this->error('Head company not found.');

            return self::FAILURE;
        }

        $input = [
            'name' => $this->argument('name'),
            'code' => $this->argument('code'),
            'currency' => $this->option('currency') ?: null,
            'timezone' => $this->option('timezone') ?: null,
        ];
        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('companies', 'code')],
            'currency' => ['nullable', Rule::in(['IDR', 'USD', 'SGD', 'EUR'])],
            'timezone' => ['nullable', Rule::in(DateTimeZone::listIdentifiers())],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $emails = array_values(array_unique((array) $this->option('member')));
        $members = User::query()->whereIn('email', $emails)->get();

        if ($members->count() !== count($emails)) {
            $this->error('Unknown member email: '.implode(', ', array_diff($emails, $members->pluck('email')->all())));

            return self::FAILURE;
        }

        try {
            $company = $hierarchy->createSubsidiary($head, $input);
        } catch (HttpExceptionInterface $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($members as $user) {
            $memberships->grant($user, $company);
        }

        $this->info("Subsidiary {$company->name} ({$company->code}, #{$company->id}) created under {$head->name}.");

        return self::SUCCESS;
    }
}
