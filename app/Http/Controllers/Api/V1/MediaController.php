<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Admin\MediaController as AdminMediaController;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\User;
use App\Services\Core\ApiTokenService;
use App\Support\RecordNotFound;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Modules\Service\Models\PromoBanner;
use Modules\Service\Queries\CustomerAudienceQuery;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function __invoke(Request $request, Media $media, AdminMediaController $controller): StreamedResponse
    {
        try {
            RecordNotFound::abortUnless($this->personaMayFetch($request->user(), $media));

            return $controller->show($media);
        } catch (AuthorizationException) {
            RecordNotFound::abort();
        }
    }

    /** The token's app, not the user's roles, decides which media it reaches: a dual-role user must not cross apps. */
    private function personaMayFetch(User $user, Media $media): bool
    {
        $owner = $media->model;

        return match (app(ApiTokenService::class)->appOf($user)) {
            ApiTokenService::CUSTOMER => $this->customerMayFetch($user, $owner),
            ApiTokenService::TECHNICIAN => ($owner instanceof WorkOrder || $owner instanceof Ticket)
                && (int) $owner->assigned_to === (int) $user->id,
            default => false,
        };
    }

    private function customerMayFetch(User $user, mixed $owner): bool
    {
        $customer = EnsureCustomerAccess::customerFor($user);
        if ($customer === null) {
            return false;
        }

        if ($owner instanceof PromoBanner) {
            return app(CustomerAudienceQuery::class)->visibleBanners($customer)->whereKey($owner)->exists();
        }

        return $owner instanceof Ticket
            && $owner->customer_id !== null
            && (int) $owner->customer_id === (int) $customer->id;
    }
}
