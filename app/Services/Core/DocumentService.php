<?php

namespace App\Services\Core;

use App\Models\Core\DocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class DocumentService
{
    public static function upload(
        HasMedia $model,
        UploadedFile $file,
        ?string $documentTypeCode = null,
        ?string $collection = 'documents',
        array $customProperties = [],
    ): Media {
        $companyId = CompanyService::currentId();
        $documentType = null;

        if ($documentTypeCode) {
            $documentType = DocumentType::where('code', $documentTypeCode)->first();
        }

        $properties = array_merge([
            'company_id' => $companyId,
            'uploaded_by' => Auth::id(),
            'document_type_id' => $documentType?->id,
            'document_type_code' => $documentType?->code,
            'expires_at' => $documentType?->expiry_days
                ? now()->addDays($documentType->expiry_days)->toDateString()
                : null,
        ], $customProperties);

        return $model->addMedia($file)
            ->withCustomProperties($properties)
            ->toMediaCollection($collection, 'public');
    }

    public static function list(HasMedia $model, ?string $collection = 'documents'): \Illuminate\Support\Collection
    {
        return $model->getMedia($collection)->map(fn (Media $media) => [
            'id' => $media->id,
            'file_name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'collection' => $media->collection_name,
            'document_type' => $media->getCustomProperty('document_type_code'),
            'uploaded_by' => $media->getCustomProperty('uploaded_by'),
            'expires_at' => $media->getCustomProperty('expires_at'),
            'url' => $media->getUrl(),
            'created_at' => $media->created_at,
        ]);
    }

    public static function delete(Media $media): bool
    {
        $companyId = CompanyService::currentId();
        if ((int) $media->getCustomProperty('company_id') !== $companyId) {
            abort(403, 'Cannot delete media from another company.');
        }
        return $media->delete();
    }
}
