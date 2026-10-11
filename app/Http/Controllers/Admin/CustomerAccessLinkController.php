<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Core\Customer;
use App\Services\Core\CompanyService;
use App\Services\Core\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class CustomerAccessLinkController extends Controller
{
    public function __invoke(int|string $customer): RedirectResponse
    {
        $customer = Customer::forCompany(CompanyService::currentId())->with('user')->findOrFail($customer);
        Gate::authorize('update', $customer);

        $user = $customer->user;
        if ($user === null || blank($user->email)) {
            return back()->with('error', 'Customer belum punya akun portal beremail, tautan akses tidak dikirim.');
        }

        if (! $customer->is_active || ! $user->is_active) {
            return back()->with('error', 'Customer atau akun portalnya nonaktif, tautan akses tidak dikirim.');
        }

        CustomerService::sendAccessLink($user);

        return back()->with('success', 'Link akses dikirim ke '.$user->email.'.');
    }
}
