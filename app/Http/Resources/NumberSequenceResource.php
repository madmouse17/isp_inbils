<?php

namespace App\Http\Resources;

use App\Models\Core\NumberSequence;
use App\Services\Core\NumberSequenceService;
use App\Support\NumberSequences\NumberSequenceRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NumberSequence */
class NumberSequenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $definition = app(NumberSequenceRegistry::class)->get($this->entity_type);

        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type,
            'label' => $definition->label,
            'prefix' => $this->prefix,
            'template' => $this->template,
            'next_number' => $this->next_number,
            'reset_period' => $this->reset_period->value,
            'allow_manual' => $this->allow_manual,
            'preview' => NumberSequenceService::previewFor($this->resource),
            'max_length' => $definition->maxLength,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
