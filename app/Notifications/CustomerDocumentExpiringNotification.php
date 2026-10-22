<?php

namespace App\Notifications;

use App\Models\Core\Customer;
use App\Models\Core\DocumentType;
use App\Services\Core\CompanyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Bell reminder for staff who may update a customer before one of that customer's documents expires. */
class CustomerDocumentExpiringNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        public readonly int $companyId,
        public readonly int $mediaId,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return CompanyService::runAs($this->companyId, function (): array {
            $media = Media::query()->find($this->mediaId);
            $customer = $media?->model;
            $typeCode = $media?->getCustomProperty('document_type_code');
            $typeName = is_string($typeCode)
                ? DocumentType::query()->where('code', $typeCode)->value('name')
                : null;

            return [
                'type' => 'customer_document_expiring',
                'company_id' => $this->companyId,
                'customer_id' => $customer instanceof Customer ? $customer->public_id : null,
                'customer_code' => $customer instanceof Customer ? $customer->code : null,
                'customer_name' => $customer instanceof Customer ? $customer->name : null,
                'document_type' => $typeName ?: $typeCode,
                'expires_at' => $media?->getCustomProperty('expires_at'),
            ];
        });
    }
}
