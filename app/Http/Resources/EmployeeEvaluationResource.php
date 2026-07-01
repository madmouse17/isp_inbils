<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeEvaluationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'score' => $this->score,
            'customer_rating' => $this->customer_rating,
            'first_response_minutes' => $this->first_response_minutes,
            'resolution_minutes' => $this->resolution_minutes,
            'comment' => $this->comment,
            'evaluator_id' => $this->evaluator_id,
            'evaluated_at' => $this->evaluated_at,
            'created_at' => $this->created_at,
            'employee' => new UserResource($this->whenLoaded('employee')),
            'evaluator' => new UserResource($this->whenLoaded('evaluator')),
        ];
    }
}
