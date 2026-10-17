<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Location;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveLocationRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['new_parent_id' => Location::class];
    }

    public function authorize(): bool
    {
        $location = $this->route('location');

        return $location instanceof Location && ($this->user()?->can('move', $location) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'new_parent_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('company_id', CompanyService::currentId())->whereNull('deleted_at')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['new_parent_id' => 'induk baru'];
    }
}
