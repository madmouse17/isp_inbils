<?php

namespace App\Services\Platform;

use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\RoleTemplate;
use App\Services\Core\CompanyRoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * The platform's role templates: the set of roles copied into every NEW company (CompanyRoleService::copyTemplates).
 * Copy once, never resync: nothing here touches a company's roles, so an edit reaches companies created afterwards
 * only. Every write is audited in the `platform` log with the permission diff.
 */
class RoleTemplateService
{
    /** Keys the company creation depends on: the first administrator and the customer-account role. */
    public const UNDELETABLE = ['admin', 'customer'];

    private const KEY_LENGTH = 32;

    public function __construct(private readonly PlatformAuditService $audit) {}

    /** The immutable key of a new template, from its name. Empty when the name has no letter or digit. */
    public static function keyFor(string $name): string
    {
        return rtrim(substr(Str::slug($name, '_'), 0, self::KEY_LENGTH), '_');
    }

    public static function isDeletable(RoleTemplate $template): bool
    {
        return ! in_array($template->key, self::UNDELETABLE, true);
    }

    /**
     * Whether a new template may not take this key: a template has it, or a company still holds a copy that carries it.
     * The second case is a template that was deleted: its copies keep the key as their provenance, and a new template
     * with the same key would become that provenance, so PermissionCatalogue::release() would grant to those old copies
     * whatever the new template is for. A key is therefore not reused while any copy carries it.
     */
    public static function keyTaken(string $key, bool $lock = false): bool
    {
        $templates = RoleTemplate::query()->where('key', $key);

        return ($lock ? $templates->lockForUpdate() : $templates)->exists()
            || Role::withoutCompany()->where('template_key', $key)->exists();
    }

    /** @param  list<string>  $permissions */
    public function create(PlatformAdmin $actor, string $name, array $permissions): RoleTemplate
    {
        $key = self::keyFor($name);
        CompanyRoleService::assertNoPortalWithWebAccess($permissions);

        return DB::transaction(function () use ($actor, $name, $key, $permissions): RoleTemplate {
            if ($key === '' || self::keyTaken($key, lock: true)) {
                throw ValidationException::withMessages(['name' => 'Nama templat sudah dipakai.']);
            }

            $template = new RoleTemplate();
            $template->forceFill(['key' => $key, 'name' => $name])->save();
            $template->permissions()->sync($this->permissionIds($permissions));

            $this->audit->platform('role_template.created', [
                'key' => $key,
                'name' => $name,
                'permissions' => $this->permissionNames($template),
            ], $template, $actor);

            return $template;
        });
    }

    /** @param  list<string>  $permissions */
    public function update(PlatformAdmin $actor, RoleTemplate $template, string $name, array $permissions): RoleTemplate
    {
        CompanyRoleService::assertNoPortalWithWebAccess($permissions);

        return DB::transaction(function () use ($actor, $template, $name, $permissions): RoleTemplate {
            $template = RoleTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $nameBefore = $template->name;
            $before = $this->permissionNames($template);

            $template->forceFill(['name' => $name])->save();
            $template->permissions()->sync($this->permissionIds($permissions));
            $after = $this->permissionNames($template);

            if ($nameBefore !== $name || $before !== $after) {
                $this->audit->platform('role_template.updated', [
                    'key' => $template->key,
                    'name_before' => $nameBefore,
                    'name_after' => $name,
                    'permissions_added' => array_values(array_diff($after, $before)),
                    'permissions_removed' => array_values(array_diff($before, $after)),
                ], $template, $actor);
            }

            return $template;
        });
    }

    public function delete(PlatformAdmin $actor, RoleTemplate $template): void
    {
        if (! self::isDeletable($template)) {
            throw ValidationException::withMessages(['template' => 'Templat ini dipakai saat perusahaan dibuat.']);
        }

        DB::transaction(function () use ($actor, $template): void {
            $this->audit->platform('role_template.deleted', [
                'key' => $template->key,
                'name' => $template->name,
                'permissions' => $this->permissionNames($template),
            ], $template, $actor);

            $template->delete();
        });
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    private function permissionIds(array $names): array
    {
        return Permission::query()->where('guard_name', 'web')->whereIn('name', $names)->pluck('id')->all();
    }

    /** @return list<string> */
    private function permissionNames(RoleTemplate $template): array
    {
        return $template->permissions()->pluck('name')->sort()->values()->all();
    }
}
