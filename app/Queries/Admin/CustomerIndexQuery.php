<?php

namespace App\Queries\Admin;

use App\Models\Core\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CustomerIndexQuery
{
    /**
     * Company-scoped customer listing query shared by index + export.
     *
     * @return Builder<Customer>
     */
    public static function make(Request $request): Builder
    {
        $companyId = $request->user()?->company_id;

        return Customer::query()
            ->forCompany($companyId)
            ->withCount(['addresses', 'subscriptions'])
            ->when($request->input('type'), fn (Builder $q, string $v) => $q->where('type', $v))
            ->when($request->input('status'), function (Builder $q, string $v): void {
                if (in_array($v, ['active', 'inactive'], true)) {
                    $q->where('is_active', $v === 'active');
                }
            })
            ->when($request->input('search'), function (Builder $q, string $v): void {
                $term = trim($v);
                if ($term === '') {
                    return;
                }

                $q->where(function (Builder $sq) use ($term): void {
                    $like = '%'.$term.'%';
                    $sq->where('name', 'like', $like)
                        ->orWhere('code', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->latest();
    }
}
