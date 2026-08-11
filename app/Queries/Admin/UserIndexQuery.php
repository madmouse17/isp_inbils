<?php

namespace App\Queries\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class UserIndexQuery
{
    /**
     * Company-scoped user listing query shared by index + export.
     *
     * @return Builder<User>
     */
    public static function make(Request $request): Builder
    {
        $companyId = $request->user()?->company_id;

        return User::query()
            ->where('company_id', $companyId)
            ->with('roles')
            ->when($request->input('search'), function (Builder $q, string $v): void {
                $term = trim($v);
                if ($term === '') {
                    return;
                }

                $q->where(function (Builder $sq) use ($term): void {
                    $like = '%'.$term.'%';
                    $sq->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->when($request->filled('is_active'), function (Builder $q) use ($request): void {
                $raw = $request->input('is_active');
                if ($raw === null || $raw === '') {
                    return;
                }

                $q->where('is_active', filter_var($raw, FILTER_VALIDATE_BOOLEAN));
            })
            ->latest();
    }
}
