<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\Company;
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
        /** @var Company|null $company */
        $company = $user->company;

        $customer = $this->app === ApiTokenService::CUSTOMER ? EnsureCustomerAccess::customerFor($user) : null;
        $profile = $this->app === ApiTokenService::TECHNICIAN && $companyId !== null
            ? EmployeeProfile::forCompany($companyId)->where('user_id', $user->id)->first()
            : null;

        return [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'app' => $this->app,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            // The ISP behind the login, as the apps brand themselves with it after sign-in. Every field is null until the ISP fills it in.
            'company' => [
                'id' => $company?->public_id,
                /** @var string|null */
                'name' => $company?->getAttribute('name'),
                /**
                 * Absolute URL of the company logo. Public: fetch it without the bearer token. An SVG logo is served as SVG.
                 *
                 * @var string|null
                 */
                'logo_url' => $company?->getFirstMedia('logo')?->getFullUrl(),
                /**
                 * `#RRGGBB` the ISP picked on the company page; the app theme is seeded from it.
                 *
                 * @var string|null
                 */
                'brand_color' => $company?->getAttribute('brand_color'),
                /** @var string|null */
                'phone' => $company?->getAttribute('phone'),
                /**
                 * Digits and `( ) + -` only, as the ISP typed it.
                 *
                 * @var string|null
                 */
                'whatsapp' => $company?->getAttribute('whatsapp'),
                /** @var string|null */
                'email' => $company?->getAttribute('email'),
                /** @var string|null */
                'website' => $company?->getAttribute('website'),
                /**
                 * Free text such as `Senin–Sabtu 08.00–20.00`.
                 *
                 * @var string|null
                 */
                'support_hours' => $company?->getAttribute('support_hours'),
            ],
            'customer' => $customer === null ? null : ['id' => $customer->public_id, 'code' => $customer->code, 'name' => $customer->name],
            'employee' => $profile === null ? null : ['name' => $user->name, 'phone' => $profile->phone],
            'phone' => $customer->phone ?? $profile?->phone,
        ];
    }
}
