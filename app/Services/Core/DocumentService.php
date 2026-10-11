<?php

namespace App\Services\Core;

use App\Models\Core\Customer;
use App\Models\Core\DocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DocumentService
{
    private const DEFAULT_COLLECTION = 'documents';

    public static function resolveUploadTarget(string $modelType, int|string $modelId): Customer
    {
        return self::resolveCustomerTarget($modelType, $modelId);
    }

    public static function upload(
        HasMedia $model,
        UploadedFile $file,
        ?string $documentTypeCode = null,
        ?string $collection = self::DEFAULT_COLLECTION,
        array $customProperties = [],
    ): Media {
        self::assertCustomerTarget($model);
        Gate::authorize('system.setting');

        $collection = self::normalizeCollection($collection);
        $documentType = self::resolveDocumentType($documentTypeCode);

        $properties = array_merge([
            'company_id' => CompanyService::currentId(),
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

    public static function list(HasMedia $model, ?string $collection = self::DEFAULT_COLLECTION): Collection
    {
        self::assertCustomerTarget($model);

        return $model->getMedia(self::normalizeCollection($collection))->map(fn (Media $media) => [
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
        self::resolveDeleteTarget($media);
        Gate::authorize('system.setting');
        abort_unless($media->collection_name === self::DEFAULT_COLLECTION, 404);

        return $media->delete();
    }

    public static function resolveDeleteTarget(Media $media): Customer
    {
        return self::resolveMediaTarget($media);
    }

    private static function resolveCustomerTarget(string $modelType, int|string $modelId): Customer
    {
        abort_unless(self::normalizeModelType($modelType) === Customer::class, 404);

        $model = Customer::withoutCompany()->find($modelId);
        abort_unless($model instanceof Customer, 404);
        abort_unless((int) $model->company_id === (int) CompanyService::currentId(), 404);

        return $model;
    }

    private static function resolveMediaTarget(Media $media): Customer
    {
        abort_unless(self::normalizeModelType((string) $media->model_type) === Customer::class, 404);

        $model = Customer::withoutCompany()->find($media->model_id);
        abort_unless($model instanceof Customer, 404);
        abort_unless((int) $model->company_id === (int) CompanyService::currentId(), 404);

        return $model;
    }

    private static function assertCustomerTarget(HasMedia $model): Customer
    {
        abort_unless($model instanceof Customer, 404);
        abort_unless((int) $model->company_id === (int) CompanyService::currentId(), 404);

        return $model;
    }

    private static function resolveDocumentType(?string $documentTypeCode): ?DocumentType
    {
        if ($documentTypeCode === null || trim($documentTypeCode) === '') {
            return null;
        }

        $documentType = DocumentType::where('code', trim($documentTypeCode))->first();
        abort_unless($documentType instanceof DocumentType, 404);

        return $documentType;
    }

    private static function normalizeCollection(?string $collection): string
    {
        $collection = trim((string) ($collection ?: self::DEFAULT_COLLECTION));
        abort_unless($collection === self::DEFAULT_COLLECTION, 404);

        return self::DEFAULT_COLLECTION;
    }

    private static function normalizeModelType(string $modelType): string
    {
        return ltrim(trim($modelType), '\\');
    }
}
