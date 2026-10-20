<?php

namespace Modules\SPK\Policies;

use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Auth\Access\Response;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Services\SpkService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class WorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('spk.view');
    }

    public function view(User $user, WorkOrder $wo): Response|bool
    {
        if (! $this->sameCompany($user, $wo)) {
            return Response::denyAsNotFound();
        }

        if (! $user->can('spk.view')) {
            return $this->canActOnCurrentStatus($user, $wo);
        }

        return true;
    }

    /** The technician apps only ever see their own SPKs: another technician's SPK, or another company's, does not exist for them. */
    public function assignedTo(User $user, WorkOrder $wo): Response|bool
    {
        return $this->sameCompany($user, $wo) && $wo->assigned_to !== null && (int) $wo->assigned_to === (int) $user->id
            ? true
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->can('spk.create');
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, WorkOrder $wo): bool
    {
        return $user->can('spk.update');
    }

    public function edit(User $user, WorkOrder $wo): bool
    {
        return $this->update($user, $wo);
    }

    public function delete(User $user, WorkOrder $wo): bool
    {
        return $this->sameCompany($user, $wo) && $user->can('spk.delete');
    }

    public function restore(User $user, WorkOrder $wo): bool
    {
        return $this->delete($user, $wo);
    }

    public function assign(User $user, WorkOrder $wo): bool
    {
        if (! $user->is_active || ! $this->sameCompany($user, $wo)) {
            return false;
        }

        if ($wo->assigned_to !== null) {
            return $user->can('spk.reassign');
        }

        return $user->can('spk.assign');
    }

    /** Another technician may take the visit while somebody holds it; the statuses are the service's, so the button never promises what assign() refuses. */
    public function reassign(User $user, WorkOrder $wo): bool
    {
        return $this->sameCompany($user, $wo)
            && $wo->assigned_to !== null
            && in_array($wo->status, SpkService::REASSIGNABLE_STATUSES, true)
            && $user->can('spk.reassign');
    }

    public function start(User $user, WorkOrder $wo): bool
    {
        return $this->sameCompany($user, $wo)
            && $wo->status === 'assigned'
            && $user->can('spk.start')
            && $this->isAssignedTechnician($user, $wo, 'spk.start');
    }

    /** "Berangkat" is part of starting the visit: the same technician, the same status, the same permission. */
    public function enRoute(User $user, WorkOrder $wo): bool
    {
        return $this->start($user, $wo);
    }

    public function submit(User $user, WorkOrder $wo): bool
    {
        return $this->sameCompany($user, $wo)
            && $wo->status === 'in_progress'
            && $user->can('spk.submit')
            && $this->isAssignedTechnician($user, $wo, 'spk.submit');
    }

    public function approve(User $user, WorkOrder $wo): bool
    {
        return $this->sameCompany($user, $wo) && $user->can('spk.approve');
    }

    public function reject(User $user, WorkOrder $wo): bool
    {
        return $this->sameCompany($user, $wo) && $user->can('spk.reject');
    }

    public function cancel(User $user, WorkOrder $wo): bool
    {
        return $this->sameCompany($user, $wo) && $user->can('spk.cancel');
    }

    public function uploadEvidence(User $user, WorkOrder $wo): bool
    {
        if (! $this->sameCompany($user, $wo) || in_array($wo->status, ['completed', 'cancelled'], true)) {
            return false;
        }

        return $user->can('spk.update')
            || ($wo->status === 'in_progress' && $this->isAssignedTechnician($user, $wo, 'spk.evidence.upload'));
    }

    public function removeEvidence(User $user, WorkOrder $wo, Media $evidence): bool
    {
        if (! $this->sameCompany($user, $wo) || in_array($wo->status, ['completed', 'cancelled'], true)) {
            return false;
        }

        if ($user->can('spk.update')) {
            return true;
        }

        return $wo->status === 'in_progress'
            && $user->can('spk.evidence.upload')
            && (int) $evidence->getCustomProperty('uploaded_by') === (int) $user->id;
    }

    private function sameCompany(User $user, WorkOrder $wo): bool
    {
        $companyId = CompanyService::idFor($user);

        return $companyId !== null && $companyId === (int) $wo->company_id;
    }

    private function isAssignedTechnician(User $user, WorkOrder $wo, string $ability): bool
    {
        $profile = $user->relationLoaded('employeeProfile')
            ? $user->getRelation('employeeProfile')
            : EmployeeProfile::query()
                ->where('company_id', $wo->company_id)
                ->where('user_id', $user->id)
                ->first();

        return $user->is_active
            && $wo->assigned_to !== null
            && (int) $wo->assigned_to === (int) $user->id
            && $user->can($ability)
            && $profile !== null
            && (int) $profile->company_id === (int) $wo->company_id
            && $profile->status === 'active';
    }

    private function canActOnCurrentStatus(User $user, WorkOrder $wo): bool
    {
        return match ($wo->status) {
            'assigned' => $this->start($user, $wo),
            'in_progress' => $this->submit($user, $wo),
            default => false,
        };
    }
}
