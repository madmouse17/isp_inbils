<?php

namespace App\Policies;

use App\Models\Core\EmployeeEvaluation;
use App\Models\User;

class EmployeeEvaluationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('evaluation.view') || $user->can('evaluation.view.own');
    }

    public function view(User $user, EmployeeEvaluation $eval): bool
    {
        if ($user->hasRole('technician')) {
            return $eval->employee_id === $user->id;
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
}
