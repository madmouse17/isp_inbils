<?php

namespace App\Listeners;

use App\Models\PlatformAdmin;
use App\Services\Platform\ImpersonationService;
use Illuminate\Auth\Events\Logout;

class EndImpersonationOnPlatformLogout
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    public function handle(Logout $event): void
    {
        if ($event->guard !== 'platform' || ! $event->user instanceof PlatformAdmin) {
            return;
        }

        $session = app('session.store');
        if ((int) $session->get(ImpersonationService::ADMIN_ID) !== (int) $event->user->getKey()) {
            return;
        }

        $this->impersonation->end($session, 'platform_session_ended');
    }
}
