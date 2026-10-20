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

    /** The maker composes their own draft (billing.create is enough for it, not for another's or an SPK-approval draft); an issued invoice is only ever touched with billing.update. */
    public function update(User $user, Invoice $invoice): bool
    {
        return $user->can('billing.update')
            || ($invoice->status === 'draft' && $invoice->created_by !== null && (int) $invoice->created_by === (int) $user->id && $user->can('billing.create'));
    }

    public function edit(User $user, Invoice $invoice): bool
    {
        return $this->update($user, $invoice);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->can('billing.delete');
    }

    /** D2: whoever may delete a draft may bring it back. */
    public function restore(User $user, Invoice $invoice): bool
    {
        return $this->delete($user, $invoice);
    }
}
