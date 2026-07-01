<?php

namespace Modules\Billing\Policies;

use App\Models\User;
use Modules\Billing\Models\Invoice;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('billing.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can('billing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('billing.create');
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->can('billing.update');
    }

    public function edit(User $user, Invoice $invoice): bool
    {
        return $this->update($user, $invoice);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->can('billing.delete');
    }
}
