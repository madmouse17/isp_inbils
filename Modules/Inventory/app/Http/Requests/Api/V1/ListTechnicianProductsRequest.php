<?php

namespace Modules\Inventory\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\PageRequest;
use Modules\Inventory\Models\Product;

class ListTechnicianProductsRequest extends PageRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Product::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'search' => ['sometimes', 'nullable', 'string', 'max:100']];
    }

    public function search(): ?string
    {
        return $this->string('search')->trim()->value() ?: null;
    }
}
