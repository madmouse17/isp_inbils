<?php

namespace Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\Location;
use App\Support\SearchOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Service\Http\Requests\SaveAnnouncementRequest;
use Modules\Service\Models\Announcement;
use Modules\Service\Models\PromoBanner;
use Modules\Service\Models\ServicePackageCategory;
use Modules\Service\Resources\AnnouncementResource;
use Modules\Service\Resources\PromoBannerResource;
use Modules\Service\Services\AnnouncementService;

class AnnouncementController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Announcement::class);
        $tab = $request->string('tab')->toString() === 'banners' ? 'banners' : 'announcements';
        $search = trim($request->string('search')->toString());
        $status = $request->string('status')->toString();
        $status = in_array($status, Announcement::STATUSES, true) ? $status : null;

        $announcements = $tab === 'announcements'
            ? AnnouncementResource::collection(Announcement::query()
                ->with(['locations:id,public_id,code,name', 'servicePackageCategories:id,public_id,code,name'])
                ->when($search !== '', fn ($query) => $query->where('title', 'like', '%'.addcslashes($search, '\\%_').'%'))
                ->when($status !== null, fn ($query) => $query->ofStatus($status))
                ->latest('id')->paginate(10)->withQueryString())
            : null;
        $banners = $tab === 'banners'
            ? PromoBannerResource::collection(PromoBanner::query()
                ->with(['locations:id,public_id,code,name', 'servicePackageCategories:id,public_id,code,name', 'media'])
                ->when($search !== '', fn ($query) => $query->where('title', 'like', '%'.addcslashes($search, '\\%_').'%'))
                ->when($status !== null, fn ($query) => $query->ofStatus($status))
                ->orderBy('sort_order')->latest('id')->paginate(10)->withQueryString())
            : null;

        return Inertia::render('Admin/Announcements/Index', [
            'tab' => $tab,
            'announcements' => $announcements,
            'banners' => $banners,
            'counts' => ['announcements' => Announcement::query()->count(), 'banners' => PromoBanner::query()->count()],
            'filters' => $request->only(['tab', 'search']) + array_filter(['status' => $status]),
            'statuses' => Announcement::STATUSES,
            'kinds' => Announcement::KINDS,
            'audiences' => Announcement::AUDIENCES,
            'appRoutes' => PromoBanner::APP_ROUTES,
            'packageCategories' => ServicePackageCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['public_id', 'code', 'name'])
                ->map(fn (ServicePackageCategory $category): array => ['id' => $category->public_id, ...$category->only(['code', 'name'])])
                ->all(),
            'locationOptions' => SearchOptions::for(
                $request,
                'location',
                Location::query()->select(['id', 'public_id', 'code', 'name', 'path']),
                ['name', 'code'],
                fn (Location $location): array => ['value' => $location->public_id, 'label' => $location->name, 'description' => $location->path],
            ),
            'preview' => [
                'banners' => PromoBannerResource::collection(PromoBanner::query()->publishedAndLive()->with('media')->orderBy('sort_order')->latest('id')->limit(5)->get()),
                'max_banners' => 5,
                'auto_advance_seconds' => 5,
            ],
            'can' => ['manage' => (bool) $request->user()?->can('announcement.manage')],
        ]);
    }

    public function store(SaveAnnouncementRequest $request, AnnouncementService $service): RedirectResponse
    {
        Gate::authorize('create', Announcement::class);
        $service->create($request->validated());

        return back()->with('success', 'Pengumuman disimpan sebagai draf.');
    }

    public function update(SaveAnnouncementRequest $request, Announcement $announcement, AnnouncementService $service): RedirectResponse
    {
        Gate::authorize('update', $announcement);
        $service->update($announcement, $request->validated());

        return back()->with('success', 'Pengumuman diperbarui.');
    }

    public function publish(Announcement $announcement, AnnouncementService $service): RedirectResponse
    {
        Gate::authorize('update', $announcement);
        $service->publish($announcement);

        return back()->with('success', 'Pengumuman diterbitkan.');
    }

    public function deactivate(Announcement $announcement, AnnouncementService $service): RedirectResponse
    {
        Gate::authorize('update', $announcement);
        $service->deactivate($announcement);

        return back()->with('success', 'Pengumuman dinonaktifkan.');
    }

    public function destroy(Announcement $announcement, AnnouncementService $service): RedirectResponse
    {
        Gate::authorize('delete', $announcement);
        $service->deleteDraft($announcement);

        return back()->with('success', 'Draf pengumuman dihapus.');
    }
}
