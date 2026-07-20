<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->getCustomProperty('type'),
            'file_path' => $this->getUrl(),
            'url' => $this->getUrl(),
            'original_name' => $this->name,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size,
            'caption' => $this->getCustomProperty('caption'),
            'uploaded_by' => $this->getCustomProperty('uploaded_by'),
            'created_at' => $this->created_at,
            'uploaded_at' => $this->created_at,
        ];
    }
}
