<?php

namespace Tests\Traits;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Carbon\Carbon;
use Modules\Billing\Models\Invoice;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\NetworkAssetInstallation;
use Modules\Service\Models\ServicePackage;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;

/**
 * Rows for report tests, written for an explicit company id so two tenants can hold look-alike
 * data. The creators are the ones of ReportFormulaV1Test, parameterised by company id.
 */
trait BuildsReportingWorld
{
    /** @var array<int, int> */
    private array $worldCustomerIds = [];

    /** @var array<int, int> */
    private array $worldAddressIds = [];

    /** @var array<int, int> */
    private array $worldUserIds = [];

    private ?int $worldPackageId = null;

    private int $worldTicketSequence = 0;

    protected function worldSubscription(int $companyId, string $status, int $mrc, string $createdAt, ?string $terminatedAt = null): ServiceSubscription
    {
        return ServiceSubscription::factory()->create([
            'company_id' => $companyId,
            'customer_id' => $this->worldCustomerId($companyId),
            'service_package_id' => $this->worldPackageId(),
            'installation_address_id' => $this->worldAddressId($companyId),
            'status' => $status,
            'mrc_amount' => $mrc,
            'created_at' => $createdAt,
            'terminated_at' => $terminatedAt,
        ]);
    }

    protected function worldInvoice(int $companyId, string $type, string $status, int $total, int $paid, string $issueDate): Invoice
    {
        return Invoice::factory()->create([
            'company_id' => $companyId,
            'customer_id' => $this->worldCustomerId($companyId),
            'created_by' => $this->worldUserId($companyId),
            'type' => $type,
            'status' => $status,
            'issue_date' => $issueDate,
            'due_date' => Carbon::parse($issueDate)->addDays(14)->toDateString(),
            'total' => $total,
            'paid_amount' => $paid,
        ]);
    }

    protected function worldWorkOrder(int $companyId, string $type, string $status, ?string $completedAt, ?int $assignedTo = null, ?string $startedAt = null): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'company_id' => $companyId,
            'created_by' => $this->worldUserId($companyId),
            'type' => $type,
            'status' => $status,
            'assigned_to' => $assignedTo,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
        ]);
    }

    protected function worldCategory(int $companyId, string $name, string $code): TicketCategory
    {
        return TicketCategory::query()->create([
            'company_id' => $companyId,
            'name' => $name,
            'code' => $code,
            'default_sla_hours' => 4,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);
    }

    protected function worldTicket(
        int $companyId,
        TicketCategory $category,
        string $createdAt,
        ?string $firstResponseAt,
        ?string $resolvedAt,
        ?string $slaDeadline,
        ?string $status = null,
        ?int $assignedTo = null,
        ?string $responseDeadline = null,
    ): Ticket {
        // forceCreate: the Ticket factory builds a new company, category and user on every call.
        return Ticket::query()->forceCreate([
            'company_id' => $companyId,
            'code' => 'TCK-'.$companyId.'-'.++$this->worldTicketSequence,
            'title' => 'Fixture ticket '.$this->worldTicketSequence,
            'source' => 'internal',
            'category_id' => $category->id,
            'status' => $status ?? ($resolvedAt === null ? 'open' : 'resolved'),
            'priority' => 'medium',
            'assigned_to' => $assignedTo,
            'created_by' => $this->worldUserId($companyId),
            'created_at' => $createdAt,
            'first_response_at' => $firstResponseAt,
            'resolved_at' => $resolvedAt,
            'sla_deadline' => $slaDeadline,
            'response_deadline' => $responseDeadline,
        ]);
    }

    protected function worldLocation(int $companyId, string $code, string $name): Location
    {
        return Location::factory()->create(['company_id' => $companyId, 'code' => $code, 'name' => $name]);
    }

    protected function worldAsset(int $companyId, string $type, string $status, ?Location $location = null, bool $linkedToCustomer = false): NetworkAsset
    {
        return NetworkAssetFactory::new()->create([
            'company_id' => $companyId,
            'asset_type' => $type,
            'status' => $status,
            'location_id' => $location?->id,
            'customer_id' => $linkedToCustomer ? $this->worldCustomerId($companyId) : null,
        ]);
    }

    protected function worldInstallation(int $companyId, NetworkAsset $asset, Location $location, string $installedAt, ?string $removedAt = null): NetworkAssetInstallation
    {
        // forceCreate: company_id is not mass assignable on installation records.
        return NetworkAssetInstallation::query()->forceCreate([
            'company_id' => $companyId,
            'network_asset_id' => $asset->id,
            'location_id' => $location->id,
            'installed_by' => $this->worldUserId($companyId),
            'installed_at' => $installedAt,
            'removed_at' => $removedAt,
        ]);
    }

    private function worldCustomerId(int $companyId): int
    {
        return $this->worldCustomerIds[$companyId] ??= Customer::factory()->create(['company_id' => $companyId])->id;
    }

    private function worldAddressId(int $companyId): int
    {
        return $this->worldAddressIds[$companyId] ??= CustomerAddress::factory()
            ->create(['customer_id' => $this->worldCustomerId($companyId)])->id;
    }

    private function worldUserId(int $companyId): int
    {
        return $this->worldUserIds[$companyId] ??= User::factory()->create(['company_id' => $companyId, 'is_active' => true])->id;
    }

    /** One package for every tenant: its factory builds its profiles for the first company only. */
    private function worldPackageId(): int
    {
        return $this->worldPackageId ??= ServicePackage::factory()->create()->id;
    }
}
