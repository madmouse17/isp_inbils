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
        // company.switch is read in the company the user is in now. Away from home without it they still get the way
        // back (CompanyPolicy::switchTo allows going home), so a switch into a company where their role is smaller
        // never strands them.
        $memberships = match (true) {
            $user === null => collect(),
            $user->can('company.switch') => app(CompanyMembershipService::class)->companiesOf($user),
            $company !== null && (int) $company->id !== (int) $user->company_id => app(CompanyMembershipService::class)->companiesOf($user)
                ->filter(fn (array $entry): bool => $entry['is_default'] || $entry['is_current'])
                ->values(),
            default => collect(),
        };
        $canSwitch = $memberships->count() > 1;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->public_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    // The company the user acts in now; home_company_id is where they land at login.
                    'company_id' => $company?->public_id,
                    'home_company_id' => $company !== null && (int) $company->id === (int) $user->company_id ? $company->public_id : $user->company?->public_id,
                    'is_active' => $user->is_active ?? true,
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ] : null,
            ],
            // The banner must name the page the operator is on after every visit, partial reloads included.
            'impersonation' => $impersonation === null ? null : Inertia::always(fn () => $impersonation->toProp($request->getRequestUri())),
            // Logins without web access (`app.web`) use the apps; the few web pages they still reach drop the admin chrome.
            'appOnly' => $user !== null && RedirectAppOnlyRoles::isAppOnly($user),
            // A nudge for an admin who has no second factor while the company does not require it yet; once it does,
            // EnforceCompanyTwoFactor locks instead. Never shown to support staff acting as the user, who cannot enrol for them.
            'twoFactorPrompt' => $user !== null
                && $impersonation === null
                && ! EnforceCompanyTwoFactor::required($company)
                && EnforceCompanyTwoFactor::isExposed($user),
            'company' => $company ? [
                'id' => $company->public_id,
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
            // Hierarchy of the effective company, kept apart from `company` so a page may replace one without the other.
            // can_consolidate costs a query, so only users who may open the consolidated report pay for it.
            'companyHierarchy' => $company ? [
                'parent_company_id' => $company->parent?->public_id,
                // The ribbon of a subsidiary names its Head and offers the way back only when the switch is allowed.
                'parent' => $company->parent ? [
                    'id' => $company->parent->public_id,
                    'name' => $company->parent->name,
                    'can_switch' => $user->can('switchTo', $company->parent),
                ] : null,
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
