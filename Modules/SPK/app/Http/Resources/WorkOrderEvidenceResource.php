<?php

namespace Modules\SPK\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderEvidenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'file_path' => $this->file_path,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'caption' => $this->caption,
            'uploaded_by' => $this->uploaded_by,
            'uploaded_at' => $this->uploaded_at,
        ];
    }
}
