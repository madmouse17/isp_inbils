<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Impersonation\EnableWriteModeRequest;
use App\Http\Requests\Platform\Impersonation\StartImpersonationRequest;
use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Platform\ImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ImpersonationController extends Controller
{
    public function start(
        StartImpersonationRequest $request,
        Company $company,
        User $user,
        ImpersonationService $impersonation,
    ): Response {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');
        $impersonation->start($admin, $company, $user, $request->validated('reason'), $request->session());

        return Inertia::location(route('admin.dashboard'));
    }

    public function write(EnableWriteModeRequest $request, ImpersonationService $impersonation): RedirectResponse
    {
        $active = $impersonation->current($request->session()) ?? throw new ConflictHttpException('Tidak ada akses dukungan yang aktif.');
        $impersonation->changeMode($active, $request->session(), ImpersonationService::WRITE);

        return back(303)->with('success', 'Mode ubah diaktifkan.');
    }

    public function read(Request $request, ImpersonationService $impersonation): RedirectResponse
    {
        $active = $impersonation->current($request->session()) ?? throw new ConflictHttpException('Tidak ada akses dukungan yang aktif.');
        $impersonation->changeMode($active, $request->session(), ImpersonationService::READ);

        return back(303)->with('success', 'Mode baca diaktifkan.');
    }

    public function stop(Request $request, ImpersonationService $impersonation): Response
    {
        $active = $impersonation->end($request->session(), 'manual');

        $location = $active === null ? route('platform.dashboard') : route('platform.companies.show', $active->company);

        return Inertia::location($location);
    }
}
