<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Services\Core\ApiTokenService;
use App\Services\Core\CompanyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Who is signed in, as one app sees them: a customer app gets `customer`, a technician app gets `employee`, the
 * other is null. Customer and employee are looked up with an explicit company, because this also answers the
 * login, where nobody is authenticated yet and the tenant scope would match nothing.
 *
 * @property User $resource
 */
class MeResource extends JsonResource
{
    public function __construct(User $user, private readonly string $app)
    {
        parent::__construct($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $companyId = CompanyService::idFor($user);

        $customer = $this->app === ApiTokenService::CUSTOMER ? EnsureCustomerAccess::customerFor($user) : null;
        $profile = $this->app === ApiTokenService::TECHNICIAN && $companyId !== null
            ? EmployeeProfile::forCompany($companyId)->where('user_id', $user->id)->first()
            : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'app' => $this->app,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'company' => [
                'id' => $user->company_id,
                /** @var string|null */
                'name' => $user->company?->getAttribute('name'),
            ],
            'customer' => $customer === null ? null : ['id' => $customer->id, 'code' => $customer->code, 'name' => $customer->name],
            'employee' => $profile === null ? null : ['name' => $user->name, 'phone' => $profile->phone],
            'phone' => $customer->phone ?? $profile?->phone,
        ];
    }
}
