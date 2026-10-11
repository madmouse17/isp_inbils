<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Http\Requests\Api\V1\NotificationIndexRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(NotificationIndexRequest $request): AnonymousResourceCollection
    {
        $query = $this->notifications($request)
            ->when($request->unreadOnly(), fn ($query) => $query->whereNull('read_at'))
            ->latest()
            ->orderByDesc('id');

        return NotificationResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function read(Request $request, string $id): Response
    {
        /** @var DatabaseNotification $notification */
        $notification = $this->notifications($request)->findOrFail($id);
        $notification->markAsRead();

        return response()->noContent();
    }

    public function readAll(Request $request): Response
    {
        $this->notifications($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->noContent();
    }

    /** @return MorphMany<DatabaseNotification, Customer>|MorphMany<DatabaseNotification, User> */
    private function notifications(Request $request): MorphMany
    {
        $customer = $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);
        $notifiable = $customer instanceof Customer ? $customer : $request->user();

        abort_unless($notifiable instanceof User || $notifiable instanceof Customer, 401);

        return $notifiable->notifications();
    }
}
