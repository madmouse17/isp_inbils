<?php

namespace App\Policies;

use App\Models\Core\EmployeeEvaluation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EmployeeEvaluationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('evaluation.view') || $user->can('evaluation.view.own');
    }

    public function view(User $user, EmployeeEvaluation $eval): bool|Response
    {
        if ($user->hasRole('technician')) {
            return $eval->employee_id === $user->id ?: Response::denyAsNotFound();
        }

        return $user->can('evaluation.view');
    }

    public function create(User $user): bool
    {
        return $user->can('evaluation.create');
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, EmployeeEvaluation $eval): bool
    {
        return $user->can('evaluation.update') || $eval->evaluator_id === $user->id;
    }

    public function delete(User $user, EmployeeEvaluation $eval): bool
    {
        return $user->can('evaluation.delete');
    }

    public function restore(User $user, EmployeeEvaluation $eval): bool
    {
        return $this->delete($user, $eval);
    }
}
