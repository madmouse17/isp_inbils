<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsCompany;
use App\Models\User;
use App\Services\Core\CompanyHierarchyService;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyRoleService;
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
        {--member=* : Email of an existing user to add as a member (repeatable); he gets the copy of every template role he holds at home}';

    protected $description = 'Create a subsidiary company under a Head company, with the defaults of every new company.';

    public function handle(CompanyHierarchyService $hierarchy, CompanyMembershipService $memberships, CompanyRoleService $roles): int
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
        $members = User::query()->with('company')->whereIn('email', $emails)->get();

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

            // Roles are per company: the member holds nothing in the subsidiary until he is given its copies, and a copy
            // wider than what he holds at home is left for a person to give.
            ['granted' => $granted, 'by_hand' => $byHand] = $user->company === null
                ? ['granted' => [], 'by_hand' => []]
                : $roles->mirrorTemplateRoles($user, $user->company, $company);
            $this->line($granted === []
                ? "{$user->email} holds no role in {$company->name}: assign roles in Pengguna."
                : "{$user->email} was given in {$company->name}: ".implode(', ', $granted).'.');

            if ($byHand !== []) {
                $this->line("{$user->email} was not given, wider than what he holds at home: assign in Pengguna: ".implode(', ', $byHand).'.');
            }
        }

        $this->info("Subsidiary {$company->name} ({$company->code}, #{$company->id}) created under {$head->name}.");

        return self::SUCCESS;
    }
}
