<?php

namespace App\Services\Core;

use App\Models\Core\Customer;
use App\Models\Core\DocumentType;
use App\Models\User;
use App\Support\RecordNotFound;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DocumentService
{
    private const DEFAULT_COLLECTION = 'documents';

    /** A document counts as "about to expire" this many days ahead; the daily reminder (SendCustomerDocumentExpiryCommand) uses the same window. */
    public const EXPIRY_WINDOW_DAYS = 30;

    public static function resolveUploadTarget(string $modelType, string $modelId): Customer
    {
        return self::resolveCustomerTarget($modelType, $modelId);
    }

    /** The calendar day it is for the current company, as Y-m-d: the day a document expires on. */
    public static function today(): string
    {
        return now(CompanyService::current()?->timezone ?: config('app.timezone'))->toDateString();
    }

    public static function upload(
        HasMedia $model,
        UploadedFile $file,
        ?string $documentTypeCode = null,
        ?string $collection = self::DEFAULT_COLLECTION,
        array $customProperties = [],
    ): Media {
        $customer = self::assertCustomerTarget($model);
        Gate::authorize('update', $customer);

        $collection = self::normalizeCollection($collection);
        $documentType = self::resolveDocumentType($documentTypeCode);

        $properties = array_merge([
            'company_id' => CompanyService::currentId(),
            'uploaded_by' => Auth::id(),
            'document_type_id' => $documentType?->id,
            'document_type_code' => $documentType?->code,
            'expires_at' => $documentType?->expiry_days
                ? CarbonImmutable::parse(self::today())->addDays($documentType->expiry_days)->toDateString()
                : null,
        ], $customProperties);

        $media = $model->addMedia($file)
            ->withCustomProperties($properties)
            ->toMediaCollection($collection);

        AuditService::log('customer', 'document_uploaded', ['type' => $documentType?->code, 'file_name' => $media->file_name], $customer);

        return $media;
    }

    /** @return list<array<string, mixed>> */
    public static function list(HasMedia $model, ?string $collection = self::DEFAULT_COLLECTION): array
    {
        $customer = self::assertCustomerTarget($model);

        /** @var Collection<int, Media> $media */
        $media = $customer->getMedia(self::normalizeCollection($collection));
        $uploaders = User::query()->whereIn('id', $media->map(fn (Media $item): mixed => $item->getCustomProperty('uploaded_by'))->filter()->unique()->values())->pluck('public_id', 'id');
        $typeNames = DocumentType::query()->whereIn('code', $media->map(fn (Media $item): mixed => $item->getCustomProperty('document_type_code'))->filter()->unique()->values())->pluck('name', 'code');
        $today = self::today();

        return $media->map(fn (Media $item): array => [
            'id' => $item->getAttribute('public_id'),
            'file_name' => $item->file_name,
            'mime_type' => $item->mime_type,
            'size' => $item->size,
            'collection' => $item->collection_name,
            'document_type' => $item->getCustomProperty('document_type_code'),
            'document_type_name' => $typeNames->get($item->getCustomProperty('document_type_code')),
            'uploaded_by' => $uploaders->get($item->getCustomProperty('uploaded_by')),
            'expires_at' => $item->getCustomProperty('expires_at'),
            'expiry' => self::expiryState($item->getCustomProperty('expires_at'), $today),
            'url' => route('admin.media.show', $item, false),
            'created_at' => $item->created_at,
        ])->all();
    }

    public static function delete(Media $media): bool
    {
        $customer = self::resolveDeleteTarget($media);
        Gate::authorize('update', $customer);
        RecordNotFound::abortUnless($media->collection_name === self::DEFAULT_COLLECTION);

        $fileName = $media->file_name;
        $type = $media->getCustomProperty('document_type_code');
        $deleted = $media->delete();
        AuditService::log('customer', 'document_deleted', ['type' => $type, 'file_name' => $fileName], $customer);

        return $deleted;
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
        /** @var Customer $customer */
        $customer = $model;
        RecordNotFound::abortUnless((int) $customer->company_id === (int) CompanyService::currentId());

        return $customer;
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

    /** 'expired' once the day has passed, 'expiring' within the window; both days as Y-m-d of the company's calendar. */
    private static function expiryState(mixed $expiresAt, string $today): ?string
    {
        if (! is_string($expiresAt) || $expiresAt === '') {
            return null;
        }

        return match (true) {
            $expiresAt < $today => 'expired',
            $expiresAt <= CarbonImmutable::parse($today)->addDays(self::EXPIRY_WINDOW_DAYS)->toDateString() => 'expiring',
            default => null,
        };
    }

    private static function normalizeModelType(string $modelType): string
    {
        return ltrim(trim($modelType), '\\');
    }
}
