<?php

namespace Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Service\Http\Requests\SavePromoBannerRequest;
use Modules\Service\Models\PromoBanner;
use Modules\Service\Services\PromoBannerService;

class PromoBannerController extends Controller
{
    public function store(SavePromoBannerRequest $request, PromoBannerService $service): RedirectResponse
    {
        Gate::authorize('create', PromoBanner::class);
        $service->create($request->validated());

        return back()->with('success', 'Banner promo disimpan sebagai draf.');
    }

    public function update(SavePromoBannerRequest $request, PromoBanner $promoBanner, PromoBannerService $service): RedirectResponse
    {
        Gate::authorize('update', $promoBanner);
        $service->update($promoBanner, $request->validated());

        return back()->with('success', 'Banner promo diperbarui.');
    }

    public function publish(PromoBanner $promoBanner, PromoBannerService $service): RedirectResponse
    {
        Gate::authorize('update', $promoBanner);
        $service->publish($promoBanner);

        return back()->with('success', 'Banner promo diterbitkan.');
    }

    public function deactivate(PromoBanner $promoBanner, PromoBannerService $service): RedirectResponse
    {
        Gate::authorize('update', $promoBanner);
        $service->deactivate($promoBanner);

        return back()->with('success', 'Banner promo dinonaktifkan.');
    }

    public function destroy(PromoBanner $promoBanner, PromoBannerService $service): RedirectResponse
    {
        Gate::authorize('delete', $promoBanner);
        $service->deleteDraft($promoBanner);

        return back()->with('success', 'Draf banner promo dihapus.');
    }
}
