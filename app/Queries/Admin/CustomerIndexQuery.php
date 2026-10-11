<?php

namespace App\Queries\Admin;

use App\Models\Core\Customer;
use App\Services\Core\CompanyService;
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
        $companyId = CompanyService::currentId();

        return Customer::query()
            ->forCompany($companyId)
            ->withCount(['addresses', 'subscriptions'])
            ->when($request->input('type'), fn (Builder $q, mixed $v) => is_string($v) ? $q->where('type', $v) : $q)
            ->when($request->input('status'), function (Builder $q, mixed $v): void {
                if (in_array($v, Customer::STATUSES, true)) {
                    $q->where('customer_status', $v);
                }
            })
            ->when($request->input('account'), function (Builder $q, mixed $v): void {
                if (in_array($v, ['active', 'inactive'], true)) {
                    $q->where('is_active', $v === 'active');
                }
            })
            ->when($request->input('search'), function (Builder $q, mixed $v): void {
                $term = is_string($v) ? trim($v) : '';
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
