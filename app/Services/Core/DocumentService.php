<?php

namespace App\Services\Core;

use App\Models\Core\Customer;
use App\Models\Core\DocumentType;
use App\Models\User;
use App\Support\RecordNotFound;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DocumentService
{
    private const DEFAULT_COLLECTION = 'documents';

    public static function resolveUploadTarget(string $modelType, string $modelId): Customer
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
            ->toMediaCollection($collection);
    }

    public static function list(HasMedia $model, ?string $collection = self::DEFAULT_COLLECTION): Collection
    {
        self::assertCustomerTarget($model);

        $media = $model->getMedia(self::normalizeCollection($collection));
        $uploaders = User::query()->whereIn('id', $media->map(fn (Media $item): mixed => $item->getCustomProperty('uploaded_by'))->filter()->unique()->values())->pluck('public_id', 'id');

        return $media->map(fn (Media $item) => [
            'id' => $item->public_id,
            'file_name' => $item->file_name,
            'mime_type' => $item->mime_type,
            'size' => $item->size,
            'collection' => $item->collection_name,
            'document_type' => $item->getCustomProperty('document_type_code'),
            'uploaded_by' => $uploaders->get($item->getCustomProperty('uploaded_by')),
            'expires_at' => $item->getCustomProperty('expires_at'),
            'url' => route('admin.media.show', $item, false),
            'created_at' => $item->created_at,
        ]);
    }

    public static function delete(Media $media): bool
    {
        self::resolveDeleteTarget($media);
        Gate::authorize('system.setting');
        RecordNotFound::abortUnless($media->collection_name === self::DEFAULT_COLLECTION);

        return $media->delete();
    }

    public static function resolveDeleteTarget(Media $media): Customer
    {
        return self::resolveMediaTarget($media);
    }

    private static function resolveCustomerTarget(string $modelType, string $modelId): Customer
    {
        RecordNotFound::abortUnless(self::normalizeModelType($modelType) === Customer::class);

        $model = Customer::withoutCompany()->wherePublicId($modelId)->first();
        RecordNotFound::abortUnless($model instanceof Customer);
        RecordNotFound::abortUnless((int) $model->company_id === (int) CompanyService::currentId());

        return $model;
    }

    private static function resolveMediaTarget(Media $media): Customer
    {
        RecordNotFound::abortUnless(self::normalizeModelType((string) $media->model_type) === Customer::class);

        $model = Customer::withoutCompany()->find($media->model_id);
        RecordNotFound::abortUnless($model instanceof Customer);
        RecordNotFound::abortUnless((int) $model->company_id === (int) CompanyService::currentId());

        return $model;
    }

    private static function assertCustomerTarget(HasMedia $model): Customer
    {
        RecordNotFound::abortUnless($model instanceof Customer);
        RecordNotFound::abortUnless((int) $model->company_id === (int) CompanyService::currentId());

        return $model;
    }

    private static function resolveDocumentType(?string $documentTypeCode): ?DocumentType
    {
        if ($documentTypeCode === null || trim($documentTypeCode) === '') {
            return null;
        }

        $documentType = DocumentType::where('code', trim($documentTypeCode))->first();
        RecordNotFound::abortUnless($documentType instanceof DocumentType);

        return $documentType;
    }

    private static function normalizeCollection(?string $collection): string
    {
        $collection = trim((string) ($collection ?: self::DEFAULT_COLLECTION));
        RecordNotFound::abortUnless($collection === self::DEFAULT_COLLECTION);

        return self::DEFAULT_COLLECTION;
    }

    private static function normalizeModelType(string $modelType): string
    {
        return ltrim(trim($modelType), '\\');
    }
}
