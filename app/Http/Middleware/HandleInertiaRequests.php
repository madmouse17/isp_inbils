<?php

namespace App\Http\Middleware;

use App\Queries\Admin\ApprovalInboxQuery;
use App\Queries\Admin\DispatchQueueQuery;
use App\Queries\Admin\MyWorkQuery;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyService;
use App\Services\Platform\ActiveImpersonation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        /** @var ActiveImpersonation|null $impersonation */
        $impersonation = $request->attributes->get('impersonation');
        $company = $user ? CompanyService::current() : null;
        $memberships = $user?->can('company.switch')
            ? app(CompanyMembershipService::class)->companiesOf($user)
            : collect();
        $canSwitch = $memberships->count() > 1;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    // The company the user acts in now; home_company_id is where they land at login.
                    'company_id' => CompanyService::idFor($user),
                    'home_company_id' => $user->company_id,
                    'is_active' => $user->is_active ?? true,
                    'roles' => $user->roles->pluck('name'),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ] : null,
            ],
            // The banner must name the page the operator is on after every visit, partial reloads included.
            'impersonation' => $impersonation === null ? null : Inertia::always(fn () => $impersonation->toProp($request->getRequestUri())),
            // Technician/customer-only logins use the apps; the few web pages they still reach drop the admin chrome.
            'appOnly' => $user !== null && RedirectAppOnlyRoles::isAppOnly($user),
            'company' => $company ? [
                'id' => $company->id,
                'name' => $company->name,
                'code' => $company->code,
                'logo' => ($logo = $company->getFirstMedia('logo'))
                    ? '/storage/'.$logo->getPathRelativeToRoot()
                    : null,
                'currency' => $company->currency,
                'timezone' => $company->timezone,
                'settings' => $company->settings,
                'map' => CompanyService::mapDefault($company),
            ] : null,
            // Hierarchy of the effective company. Separate from `company`: pages such as the dashboard replace that prop.
            // can_consolidate costs a query, so only users who may open the consolidated report pay for it.
            'companyHierarchy' => $company ? [
                'parent_company_id' => $company->parent_company_id,
                'can_consolidate' => $user->can('report.consolidated.view') && $company->isHead(),
            ] : null,
            // The switcher: only offered to a user who may switch and has somewhere to switch to.
            'companies' => $canSwitch ? $memberships->all() : [],
            'canSwitch' => $canSwitch,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
            'workQueues' => [
                'myWorkEligible' => fn () => MyWorkQuery::isEligible($request->user()),
                // Lazy actor-scoped approval badge. Computed only when the
                // actor has approval permission for a mature domain; omitted
                // entirely otherwise so nothing leaks to non-reviewers.
                'approvalPending' => fn () => ApprovalInboxQuery::badgeCount($request),
                'dispatchUnassigned' => fn () => DispatchQueueQuery::badgeCount($request),
                'myWorkPending' => fn () => MyWorkQuery::badgeCount($request),
            ],
            'canMyWork' => fn () => MyWorkQuery::isEligible($request->user()),
            // Mirrors the environment gate of the `admin.components` route (routes/admin.php): outside
            // these environments that route is a 404 a permission check cannot reveal.
            'developerGalleryEnabled' => app()->environment(['local', 'testing']),
            'notifications' => [
                'unreadCount' => fn () => $request->user()?->unreadNotifications()->count() ?? 0,
            ],
            'app' => [
                'name' => config('app.name'),
                'locale' => 'id',
            ],
        ];
    }
}
