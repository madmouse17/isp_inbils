<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\RoleTemplates\StoreRoleTemplateRequest;
use App\Http\Requests\Platform\RoleTemplates\UpdateRoleTemplateRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleTemplateResource;
use App\Models\PlatformAdmin;
use App\Models\RoleTemplate;
use App\Services\Platform\RoleTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

/**
 * The role templates every new company starts from (ADR-004). Editing a template never reaches a company that
 * already has its roles; the writes sit behind the platform step-up (routes/platform/roles.php) and are audited by
 * the service.
 */
class RoleTemplateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Platform/RoleTemplates/Index', [
            'templates' => RoleTemplateResource::collection(RoleTemplate::query()->withCount('permissions')->orderBy('id')->get()),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Platform/RoleTemplates/Create', ['permissions' => $this->permissions()]);
    }

    public function store(StoreRoleTemplateRequest $request, RoleTemplateService $templates): RedirectResponse
    {
        $templates->create($this->admin($request), $request->validated('name'), $request->permissionNames());

        return redirect()->route('platform.role-templates.index')->with('success', 'Templat dibuat.');
    }

    public function edit(RoleTemplate $template): Response
    {
        return Inertia::render('Platform/RoleTemplates/Edit', [
            'template' => new RoleTemplateResource($template->load('permissions')),
            'permissions' => $this->permissions(),
        ]);
    }

    public function update(UpdateRoleTemplateRequest $request, RoleTemplate $template, RoleTemplateService $templates): RedirectResponse
    {
        $templates->update($this->admin($request), $template, $request->validated('name'), $request->permissionNames());

        return redirect()->route('platform.role-templates.index')->with('success', 'Templat diperbarui. Peran perusahaan yang sudah ada tidak berubah.');
    }

    public function destroy(Request $request, RoleTemplate $template, RoleTemplateService $templates): RedirectResponse
    {
        $templates->delete($this->admin($request), $template);

        return redirect()->route('platform.role-templates.index')->with('success', 'Templat dihapus.');
    }

    private function permissions(): AnonymousResourceCollection
    {
        return PermissionResource::collection(Permission::query()->where('guard_name', 'web')->orderBy('name')->get());
    }

    private function admin(Request $request): PlatformAdmin
    {
        /** @var PlatformAdmin */
        return $request->user('platform');
    }
}
