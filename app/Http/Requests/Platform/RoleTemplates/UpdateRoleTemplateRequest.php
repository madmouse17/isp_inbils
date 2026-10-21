<?php

namespace App\Http\Requests\Platform\RoleTemplates;

use App\Models\RoleTemplate;
use App\Support\PermissionCatalogue;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The key is immutable, so only the name must stay unique; the rest is the store request's. The Admin template is the
 * first administrator of every new company: it must keep roles.manage and app.web, or a company would be created that
 * nobody can administer.
 */
class UpdateRoleTemplateRequest extends StoreRoleTemplateRequest
{
    public const ADMIN_KEY = 'admin';

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->template()->key !== self::ADMIN_KEY) {
                return;
            }

            $missing = array_diff([PermissionCatalogue::ADMINISTRATION, PermissionCatalogue::WEB], array_filter((array) $this->input('permissions'), 'is_string'));

            if ($missing !== []) {
                $validator->errors()->add('permissions', 'Templat Admin harus tetap memuat '.implode(' dan ', $missing).', kalau tidak perusahaan baru tidak punya admin.');
            }
        }];
    }

    /** @return list<mixed> */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255', Rule::unique('role_templates', 'name')->ignore($this->template()->id)];
    }

    private function template(): RoleTemplate
    {
        /** @var RoleTemplate */
        return $this->route('template');
    }
}
