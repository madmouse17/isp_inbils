<?php

namespace Modules\Inventory\Policies;

use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Modules\Inventory\Models\InventoryRequest;

class InventoryRequestPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.request.view.own')
            || $user->hasPermissionTo('inventory.request.view.all');
    }

    public function view(User $user, InventoryRequest $request): bool|Response
    {
        if ($request->company_id !== CompanyService::idFor($user)) {
            return $this->denyAsNotFound();
        }

        if ($user->hasPermissionTo('inventory.request.view.all')) {
            return true;
        }

        if ($user->hasPermissionTo('inventory.request.view.own')) {
            return (int) $request->requested_by === (int) $user->id ? true : $this->denyAsNotFound();
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('inventory.request.create');
    }

    public function update(User $user, InventoryRequest $request): bool
    {
        if ($request->company_id !== CompanyService::idFor($user)) {
            return false;
        }

        if ($request->status !== 'draft') {
            return false;
        }

        return (int) $request->requested_by === (int) $user->id
            && $user->hasPermissionTo('inventory.request.create');
    }

    public function submit(User $user, InventoryRequest $request): bool
    {
        if ($request->company_id !== CompanyService::idFor($user)) {
            return false;
        }

        if ($request->status !== 'draft') {
            return false;
        }

        return (int) $request->requested_by === (int) $user->id
            && $user->hasPermissionTo('inventory.request.create');
    }

    public function cancel(User $user, InventoryRequest $request): bool|Response
    {
        if ($this->mayCancel($user, $request)) {
            return true;
        }

        // A refusal for a request the person cannot see is the 404 of `view`: a 403 would tell them it exists.
        $seen = $this->view($user, $request);

        return $seen instanceof Response ? $seen : false;
    }

    private function mayCancel(User $user, InventoryRequest $request): bool
    {
        if ($request->company_id !== CompanyService::idFor($user)) {
            return false;
        }

        if (! in_array($request->status, ['draft', 'submitted'], true)) {
            return false;
        }

        if ($user->hasPermissionTo('inventory.request.cancel.own')) {
            return (int) $request->requested_by === (int) $user->id;
        }

        return $user->hasPermissionTo('inventory.request.approve');
    }

    public function approve(User $user, InventoryRequest $request): bool
    {
        if ($request->company_id !== CompanyService::idFor($user)) {
            return false;
        }

        if ($request->status !== 'submitted') {
            return false;
        }

        // Maker-checker rule: requester cannot approve their own request
        if ((int) $request->requested_by === (int) $user->id) {
            return false;
        }

        return $user->hasPermissionTo('inventory.request.approve');
    }

    public function reject(User $user, InventoryRequest $request): bool
    {
        if ($request->company_id !== CompanyService::idFor($user)) {
            return false;
        }

        if ($request->status !== 'submitted') {
            return false;
        }

        // Maker-checker rule: requester cannot reject their own request
        if ((int) $request->requested_by === (int) $user->id) {
            return false;
        }

        return $user->hasPermissionTo('inventory.request.reject');
    }

    public function prepare(User $user, InventoryRequest $request): bool
    {
        if ($request->company_id !== CompanyService::idFor($user)) {
            return false;
        }

        if ($request->status !== 'approved_reserved') {
            return false;
        }

        // Maker-checker: the approver cannot also prepare the goods
        if ((int) $request->approved_by === (int) $user->id) {
            return false;
        }

        return $user->hasPermissionTo('inventory.request.prepare');
    }

    public function ready(User $user, InventoryRequest $request): bool
    {
        if ($request->company_id !== CompanyService::idFor($user)) {
            return false;
        }

        if ($request->status !== 'preparing') {
            return false;
        }

        return $user->hasPermissionTo('inventory.request.prepare');
    }

    public function issue(User $user, InventoryRequest $request): bool
    {
        if ($request->company_id !== CompanyService::idFor($user)) {
            return false;
        }

        if ($request->status !== 'ready_for_pickup') {
            return false;
        }

        // Maker-checker: requester cannot issue to themselves
        if ((int) $request->requested_by === (int) $user->id) {
            return false;
        }

        // Maker-checker: the approver cannot also hand the goods over
        if ((int) $request->approved_by === (int) $user->id) {
            return false;
        }

        return $user->hasPermissionTo('inventory.request.issue');
    }

    public function audit(User $user): bool
    {
        return $user->hasPermissionTo('inventory.request.audit');
    }
}
