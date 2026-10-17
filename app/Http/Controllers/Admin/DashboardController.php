<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Queries\Admin\DashboardAdminStatsQuery;
use App\Queries\Admin\DashboardQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('dashboard.view');

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $notifications = DashboardQuery::notifications($user);

        $props = [
            'sections' => DashboardQuery::sections($request),
            'notifications' => NotificationResource::collection($notifications['latest'])
                ->additional(['unreadCount' => $notifications['unreadCount']]),
        ];

        if (DashboardAdminStatsQuery::isEligible($user)) {
            $props['admin'] = DashboardAdminStatsQuery::stats($user);
        }

        return Inertia::render('Admin/Dashboard/Index', $props);
    }
}
