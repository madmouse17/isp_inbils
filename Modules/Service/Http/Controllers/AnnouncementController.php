<?php

namespace Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\Location;
use App\Support\SearchOptions;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Service\Http\Requests\AnnouncementAudienceRequest;
use Modules\Service\Http\Requests\SaveAnnouncementRequest;
use Modules\Service\Models\Announcement;
use Modules\Service\Models\PromoBanner;
use Modules\Service\Models\ServicePackageCategory;
use Modules\Service\Queries\CustomerAudienceQuery;
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

        $page = $tab === 'announcements'
            ? Announcement::query()
                ->with(['locations:id,public_id,code,name', 'servicePackageCategories:id,public_id,code,name'])
                ->when($search !== '', fn ($query) => $query->where('title', 'like', '%'.addcslashes($search, '\\%_').'%'))
                ->when($status !== null, fn ($query) => $query->ofStatus($status))
                ->latest('id')->paginate(10)->withQueryString()
            : null;
        // The models, taken before AnnouncementResource::collection() swaps the paginator's items for resources.
        $rows = $page === null ? [] : $page->getCollection()->all();
        $banners = $tab === 'banners'
            ? PromoBannerResource::collection(PromoBanner::query()
                ->with(['locations:id,public_id,code,name', 'servicePackageCategories:id,public_id,code,name', 'media'])
                ->when($search !== '', fn ($query) => $query->where('title', 'like', '%'.addcslashes($search, '\\%_').'%'))
                ->when($status !== null, fn ($query) => $query->ofStatus($status))
                ->orderBy('sort_order')->latest('id')->paginate(10)->withQueryString())
            : null;

        return Inertia::render('Admin/Announcements/Index', [
            'tab' => $tab,
            'announcements' => $page === null ? null : AnnouncementResource::collection($page),
            // What publishing a draft of this page reaches, by announcement id: the confirmation says "untuk {n} pelanggan".
            // A closure, so a reload of the table that does not ask for it runs none of the count queries.
            'recipientCounts' => fn (): array => $this->recipientCounts(collect($rows), app(CustomerAudienceQuery::class)),
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

        // "Simpan & Terbitkan": the form also asks to publish what it saves.
        if ($request->boolean('publish')) {
            return $this->savedAndPublished($service, fn (): Announcement => $service->create($request->validated()));
        }

        $service->create($request->validated());

        return back()->with('success', 'Pengumuman disimpan sebagai draf.');
    }

    public function update(SaveAnnouncementRequest $request, Announcement $announcement, AnnouncementService $service): RedirectResponse
    {
        Gate::authorize('update', $announcement);

        if ($request->boolean('publish')) {
            return $this->savedAndPublished($service, fn (): Announcement => $service->update($announcement, $request->validated()));
        }

        $service->update($announcement, $request->validated());

        return back()->with('success', 'Pengumuman diperbarui.');
    }

    /**
     * How many customers an announcement with this targeting would reach, for the confirmation of the editor: the
     * targeting is the form's, not a saved row. The count is all that leaves.
     */
    public function audience(AnnouncementAudienceRequest $request, CustomerAudienceQuery $audience): JsonResponse
    {
        Gate::authorize('viewAny', Announcement::class);

        return response()->json(['count' => $audience->recipientCount(
            $request->string('audience')->toString(),
            array_map('intval', $request->validated('location_ids', [])),
            array_map('intval', $request->validated('service_package_category_ids', [])),
        )]);
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

    /**
     * One transaction: when publishing is refused (the period is over, the row is published already) the row is not
     * saved either, so the form can be fixed and sent again without a second draft behind it.
     *
     * @param  Closure(): Announcement  $save
     */
    private function savedAndPublished(AnnouncementService $service, Closure $save): RedirectResponse
    {
        DB::transaction(function () use ($service, $save): void {
            $announcement = $save();
            Gate::authorize('update', $announcement);
            $service->publish($announcement);
        });

        return back()->with('success', 'Pengumuman disimpan dan diterbitkan.');
    }

    /**
     * Publishing is asked for drafts only, so only they are counted: one query per draft of the page (at most ten).
     * The targets come from the rows already loaded.
     *
     * @param  Collection<int, Announcement>  $announcements
     * @return array<string, int>
     */
    private function recipientCounts(Collection $announcements, CustomerAudienceQuery $audience): array
    {
        return $announcements
            ->filter(fn (Announcement $announcement): bool => $announcement->published_at === null)
            ->mapWithKeys(fn (Announcement $announcement): array => [$announcement->public_id => $audience->recipientCount(
                $announcement->audience,
                array_map('intval', $announcement->locations->modelKeys()),
                array_map('intval', $announcement->servicePackageCategories->modelKeys()),
            )])
            ->all();
    }
}
