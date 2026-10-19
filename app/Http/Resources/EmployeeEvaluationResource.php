<?php

namespace App\Http\Resources;

use App\Models\Core\EmployeeEvaluation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeEvaluation */
class EmployeeEvaluationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'employee_id' => $this->employee?->public_id,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->referencePublicId(),
            'score' => $this->score,
            'customer_rating' => $this->customer_rating,
            'first_response_minutes' => $this->first_response_minutes,
            'resolution_minutes' => $this->resolution_minutes,
            'comment' => $this->comment,
            'evaluator_id' => $this->evaluator?->public_id,
            'evaluated_at' => $this->evaluated_at,
            ...$this->lifecycleAttributes(),
            'created_at' => $this->created_at,
            'employee' => new UserResource($this->whenLoaded('employee')),
            'evaluator' => new UserResource($this->whenLoaded('evaluator')),
        ];
    }
}
