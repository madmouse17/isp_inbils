<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\PermissionCatalogue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class PermissionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'name' => $this->name,
            'group' => Str::before($this->name, '.'),
            // A tenant user grants only the guarded permissions he holds himself (G1). The operator panel offers
            // every permission, so a request of a platform admin carries no flag and a form reads "absent" as grantable.
            ...($user instanceof User ? ['grantable' => in_array($this->name, PermissionCatalogue::UNGUARDED, true) || $user->can($this->name)] : []),
        ];
    }
}
