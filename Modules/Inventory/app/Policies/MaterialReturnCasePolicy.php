<?php

namespace Modules\Inventory\Policies;

use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Modules\Inventory\Models\MaterialReturnCase;

class MaterialReturnCasePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.return.view.own')
            || $user->hasPermissionTo('inventory.return.view.all');
    }

    public function view(User $user, MaterialReturnCase $case): bool|Response
    {
        if ($case->company_id !== CompanyService::idFor($user)) {
            return $this->denyAsNotFound();
        }

        if ($user->hasPermissionTo('inventory.return.view.all')) {
            return true;
        }

        if ($user->hasPermissionTo('inventory.return.view.own')) {
            return (int) $case->technician_id === (int) $user->id ? true : $this->denyAsNotFound();
        }

        return false;
    }

    public function declare(User $user, MaterialReturnCase $case): bool
    {
        if ($case->company_id !== CompanyService::idFor($user)) {
            return false;
        }

        if (! in_array($case->status, ['awaiting_declaration', 'correction_required'], true)) {
            return false;
        }

        return (int) $case->technician_id === (int) $user->id
            && $user->hasPermissionTo('inventory.return.declare');
    }

    public function receive(User $user, MaterialReturnCase $case): bool
    {
        if ($case->company_id !== CompanyService::idFor($user)) {
            return false;
        }

        if ($case->status !== 'awaiting_receipt') {
            return false;
        }

        // Maker-checker: custodian technician cannot receive own physical return
        if ((int) $case->technician_id === (int) $user->id) {
            return false;
        }

        return $user->hasPermissionTo('inventory.return.receive');
    }

    public function resolve(User $user, MaterialReturnCase $case): bool
    {
        if ($case->company_id !== CompanyService::idFor($user)) {
            return false;
        }

        if ($case->status !== 'exception_open') {
            return false;
        }

        // Maker-checker: technician cannot resolve; receiver cannot resolve
        if ((int) $case->technician_id === (int) $user->id) {
            return false;
        }

        if ((int) $case->received_by === (int) $user->id) {
            return false;
        }

        return $user->hasPermissionTo('inventory.return.resolve');
    }
}
