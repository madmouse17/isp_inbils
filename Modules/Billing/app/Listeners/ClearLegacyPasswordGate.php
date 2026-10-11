<?php

namespace Modules\Billing\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Modules\Billing\Services\PortalCredentials;

/** A completed password reset is the only thing that opens the portal for a legacy (flagged) customer login. */
class ClearLegacyPasswordGate
{
    public function handle(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            PortalCredentials::completeReset($event->user);
        }
    }
}
