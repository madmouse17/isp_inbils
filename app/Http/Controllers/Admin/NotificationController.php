<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class NotificationController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Notifications/Index', [
            'notifications' => NotificationResource::collection($notifications)->additional([
                'unreadCount' => $request->user()->unreadNotifications()->count(),
            ]),
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $request->user()
            ->notifications()
            ->findOrFail($notification)
            ->markAsRead();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()
            ->unreadNotifications()
            ->update(['read_at' => now()]);

        return back();
    }
}
