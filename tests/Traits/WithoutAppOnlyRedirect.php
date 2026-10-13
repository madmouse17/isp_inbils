<?php

namespace Tests\Traits;

use App\Http\Middleware\RedirectAppOnlyRoles;

/**
 * For tests of a role's web permissions or data scope: a technician-only or customer-only login never reaches the
 * admin pages (RedirectAppOnlyRoles), so these tests switch the redirect off and keep exercising the policies
 * and query scopes behind it. The redirect itself is covered by AppOnlyRedirectTest.
 */
trait WithoutAppOnlyRedirect
{
    protected function setUpWithoutAppOnlyRedirect(): void
    {
        $this->withoutMiddleware(RedirectAppOnlyRoles::class);
    }
}
