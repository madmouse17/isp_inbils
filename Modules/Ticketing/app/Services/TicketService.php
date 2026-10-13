<?php

namespace Modules\Ticketing\Services;

use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Models\TicketComment;
use Modules\Ticketing\Notifications\TicketAssignedNotification;
use Modules\Ticketing\Notifications\TicketCustomerUpdateNotification;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TicketService
{
    private const MISSING_EXPECTED_ASSIGNED_TO = '__missing_expected_assigned_to__';

    private const MISSING_EXPECTED_UPDATED_AT = '__missing_expected_updated_at__';

    /** A ticket is still being worked in these statuses; a file may be withdrawn only until it leaves them. */
    private const OPEN_STATUSES = ['open', 'assigned', 'on_progress'];

    public static function create(array $data, int $createdBy): Ticket
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $companyId = CompanyService::currentId();
            abort_if($companyId === null, 403, 'Company context is required.');

            $category = TicketCategory::withoutCompany()->where('company_id', $companyId)->lockForUpdate()->findOrFail($data['category_id'] ?? null);
            self::assertCreateReferences($data, $companyId, $category);

            $data['company_id'] = $companyId;
            $data['category_id'] = $category->id;
            $data['code'] = NumberSequenceService::generate('ticket', $companyId);
            $data['status'] = $data['status'] ?? 'open';
            $data['created_by'] = $createdBy;

            $ticket = new Ticket($data);
            $ticket->setRelation('category', $category);
            $deadlines = self::computeDeadlines($ticket);
            $ticket->fill(['sla_deadline' => $deadlines['resolution'], 'response_deadline' => $deadlines['response']])->save();

            AuditService::log('ticket', 'created', [
                'code' => $ticket->code,
                'category_id' => $ticket->category_id,
            ], $ticket);

            self::notifyCustomer($ticket, 'created');

            return $ticket->fresh();
        });
    }

    /**
     * When the ticket must get its first response and its resolution, counted in calendar hours from now.
     * Priority urgent halves both targets, rounded up.
     *
     * @return array{response: ?CarbonImmutable, resolution: CarbonImmutable}
     */
    public static function computeDeadlines(Ticket $ticket): array
    {
        $target = self::slaTarget($ticket);
        $urgent = ($ticket->priority ?? $ticket->category->default_priority) === 'urgent';
        $from = now()->toImmutable();
        $within = static fn (int $hours): CarbonImmutable => $from->addHours($urgent ? (int) ceil($hours / 2) : $hours);

        return [
            'response' => $target['response_hours'] === null ? null : $within($target['response_hours']),
            'resolution' => $within($target['resolution_hours']),
        ];
    }

    /**
     * Where the targets of the ticket come from, as the ticket page names it: an SLA tier or a category.
     *
     * @return array{type: 'tier'|'category', name: string}
     */
    public static function deadlineSource(Ticket $ticket): array
    {
        ['type' => $type, 'name' => $name] = self::slaTarget($ticket);

        return ['type' => $type, 'name' => $name];
    }

    /**
     * The targets a ticket is held to: those of the active SLA tier of its subscription's package, else those of its
     * category. Tiers and subscriptions of another company never load, so they never lend their targets.
     *
     * @return array{type: 'tier'|'category', name: string, response_hours: ?int, resolution_hours: int}
     */
    private static function slaTarget(Ticket $ticket): array
    {
        $ticket->loadMissing(['category', 'subscription.servicePackage.slaTier']);
        $tier = $ticket->subscription?->servicePackage?->slaTier;

        if ($tier?->is_active) {
            return ['type' => 'tier', 'name' => $tier->name, 'response_hours' => $tier->response_time_hours, 'resolution_hours' => $tier->resolution_time_hours];
        }

        return [
            'type' => 'category',
            'name' => $ticket->category->name,
            'response_hours' => $ticket->category->default_response_hours,
            'resolution_hours' => $ticket->category->default_sla_hours,
        ];
    }

    public static function assign(Ticket $ticket, int $handlerId, int $assignedBy, mixed $expectedAssignedTo = self::MISSING_EXPECTED_ASSIGNED_TO, mixed $expectedUpdatedAt = self::MISSING_EXPECTED_UPDATED_AT): Ticket
    {
        return DB::transaction(function () use ($ticket, $handlerId, $assignedBy, $expectedAssignedTo, $expectedUpdatedAt) {
            abort_if(
                $expectedAssignedTo === self::MISSING_EXPECTED_ASSIGNED_TO
                || $expectedUpdatedAt === self::MISSING_EXPECTED_UPDATED_AT,
                422,
                'Expected assignment state is required.'
            );
            abort_unless(
                $expectedAssignedTo === null || is_int($expectedAssignedTo),
                422,
                'Expected assignee must be an integer or null.'
            );
            self::assertCanonicalExpectedUpdatedAt($expectedUpdatedAt);
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            abort_if(! in_array($ticket->status, ['open', 'assigned']), 422, 'Ticket must be open or assigned to assign.');

            $ability = $ticket->assigned_to === null ? 'ticket.assign' : 'ticket.reassign';
            self::assertAssignmentActor($assignedBy, $ticket->company_id, $ability);

            if ($expectedAssignedTo === null) {
                $assignmentMatches = $ticket->assigned_to === null;
            } else {
                $assignmentMatches = (int) $ticket->assigned_to === (int) $expectedAssignedTo;
            }

            if (! $assignmentMatches) {
                abort(409, 'Ticket assignment conflict: item has been assigned by another user.');
            }

            $canonicalUpdatedAt = $ticket->updated_at?->utc()->format('Y-m-d\\TH:i:s.v\\Z');
            if ($expectedUpdatedAt === null || $canonicalUpdatedAt !== $expectedUpdatedAt) {
                throw new ConflictHttpException(
                    'Ticket assignment conflict: item has been assigned by another user.',
                    null,
                    409
                );
            }

            $companySettings = data_get($ticket->company, 'settings', []);
            if (($companySettings['forbid_self_assignment'] ?? false) && (int) $assignedBy === (int) $handlerId) {
                abort(422, 'Self-assignment is forbidden by company policy.');
            }

            $handler = User::query()
                ->where('company_id', $ticket->company_id)
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereHas('permissions', fn ($permissionQuery) => $permissionQuery->whereIn('name', ['ticket.start', 'ticket.resolve', 'ticket.close']))
                        ->orWhereHas('roles.permissions', fn ($permissionQuery) => $permissionQuery->whereIn('name', ['ticket.start', 'ticket.resolve', 'ticket.close']));
                })
                ->findOrFail($handlerId);

            $previousHandlerId = $ticket->assigned_to;
            $ticket->update(['assigned_to' => $handlerId, 'status' => 'assigned']);

            if ($previousHandlerId !== $handlerId) {
                // After commit: a queued worker must never see the assignment before it exists.
                Notification::send($handler, (new TicketAssignedNotification($ticket->id, $ticket->code, $ticket->company_id))->afterCommit());
            }

            AuditService::log('ticket', 'assigned', [
                'handler_id' => $handlerId,
                'assigned_by' => $assignedBy,
            ], $ticket);

            if ($previousHandlerId === null) {
                self::notifyCustomer($ticket, 'assigned');
            }

            return $ticket->fresh();
        });
    }

    public static function startWork(Ticket $ticket): Ticket
    {
        $expectedStatus = $ticket->status;
        self::assertAssignedActor($ticket, 'ticket.start');

        return DB::transaction(function () use ($ticket, $expectedStatus) {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            self::assertAssignedActor($ticket, 'ticket.start');
            self::throwStatusConflictIfChanged($ticket, $expectedStatus, 'assigned', 'Ticket start conflict: item state changed by another user.');
            abort_if($ticket->status !== 'assigned', 422, 'Ticket must be assigned to start.');
            $ticket->update([
                'status' => 'on_progress',
                'first_response_at' => $ticket->first_response_at ?? now(),
            ]);

            AuditService::log('ticket', 'started', ['code' => $ticket->code], $ticket);

            return $ticket->fresh();
        });
    }

    public static function addComment(Ticket $ticket, array $data, int $authorId): TicketComment
    {
        self::assertCompany($ticket);

        return TicketComment::create([
            'ticket_id' => $ticket->id,
            'author_id' => $authorId,
            'body' => $data['body'],
            'is_internal' => $data['is_internal'] ?? false,
        ]);
    }

    /**
     * A ticket as the app user may reach it: any ticket of the company for a technician (the policy then narrows
     * it to the assigned handler), only the customer's own for a customer, so a stranger's id is a plain 404.
     */
    public static function findForApp(int $id, ?Customer $customer): Ticket
    {
        return Ticket::query()
            ->when($customer, fn (Builder $query, Customer $own): Builder => $query->where('customer_id', $own->id))
            ->findOrFail($id);
    }

    /** The web's rules for attaching: the permission, plus the ticket policy (company, assignment or ownership). */
    public static function mayAttach(User $user, Ticket $ticket): bool
    {
        return $user->can('ticket.attachment.upload')
            && $user->can('view', $ticket)
            && $user->can('attach', $ticket);
    }

    /** Whoever uploaded a file may withdraw it, but only while the ticket is still being worked. */
    public static function mayRemoveAttachment(User $user, Ticket $ticket, Media $media): bool
    {
        return in_array($ticket->status, self::OPEN_STATUSES, true)
            && (int) $media->getCustomProperty('uploaded_by') === (int) $user->id
            && self::mayAttach($user, $ticket);
    }

    /**
     * Files go to the private disk. The Idempotency-Key is kept on the media, so a retry that outlives the
     * response cache still finds the file it already stored instead of adding a second one.
     *
     * @param  array{file: UploadedFile, caption?: ?string}  $data
     */
    public static function addAttachment(Ticket $ticket, User $actor, array $data, ?string $idempotencyKey = null): Media
    {
        return DB::transaction(function () use ($ticket, $actor, $data, $idempotencyKey): Media {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            abort_unless(self::mayAttach($actor, $ticket), 403);

            if ($idempotencyKey !== null) {
                $existing = $ticket->getMedia('attachments')->first(
                    fn (Media $media): bool => $media->getCustomProperty('idempotency_key') === $idempotencyKey
                );
                if ($existing instanceof Media) {
                    return $existing;
                }
            }

            $properties = [
                'company_id' => $ticket->company_id,
                'uploaded_by' => $actor->id,
                'caption' => $data['caption'] ?? null,
            ];
            if ($idempotencyKey !== null) {
                $properties['idempotency_key'] = $idempotencyKey;
            }

            $media = $ticket->addMedia($data['file'])->withCustomProperties($properties)->toMediaCollection('attachments');

            AuditService::log('ticket', 'attachment_uploaded', ['media_id' => $media->id, 'uploaded_by' => $actor->id], $ticket);

            return $media;
        });
    }

    public static function removeAttachment(Ticket $ticket, int $mediaId, User $actor): void
    {
        DB::transaction(function () use ($ticket, $mediaId, $actor): void {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            abort_unless(self::mayAttach($actor, $ticket), 403);
            $media = $ticket->getMedia('attachments')->first(fn (Media $item): bool => (int) $item->id === $mediaId);
            abort_unless($media instanceof Media, 404);
            abort_unless(self::mayRemoveAttachment($actor, $ticket, $media), 403);

            $media->delete();

            AuditService::log('ticket', 'attachment_removed', ['media_id' => $mediaId, 'removed_by' => $actor->id], $ticket);
        });
    }

    public static function resolve(Ticket $ticket, string $resolutionNote): Ticket
    {
        $expectedStatus = $ticket->status;
        self::assertAssignedActor($ticket, 'ticket.resolve');

        return DB::transaction(function () use ($ticket, $resolutionNote, $expectedStatus) {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            self::assertAssignedActor($ticket, 'ticket.resolve');
            self::throwStatusConflictIfChanged($ticket, $expectedStatus, 'on_progress', 'Ticket resolve conflict: item state changed by another user.');
            abort_if($ticket->status !== 'on_progress', 422, 'Ticket must be on progress to resolve.');
            $ticket->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolution_note' => $resolutionNote,
            ]);

            AuditService::log('ticket', 'resolved', ['code' => $ticket->code], $ticket);

            self::notifyCustomer($ticket, 'resolved');

            return $ticket->fresh();
        });
    }

    public static function close(Ticket $ticket): Ticket
    {
        $expectedStatus = $ticket->status;
        self::assertCloser($ticket);
        abort_if($ticket->status !== 'resolved', 422, 'Ticket must be resolved to close.');

        return DB::transaction(function () use ($ticket, $expectedStatus) {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            self::assertCloser($ticket);
            self::throwStatusConflictIfChanged($ticket, $expectedStatus, 'resolved', 'Ticket close conflict: item state changed by another user.');
            abort_if($ticket->status !== 'resolved', 422, 'Ticket must be resolved to close.');
            $ticket->update(['status' => 'closed', 'closed_at' => now()]);

            AuditService::log('ticket', 'closed', ['code' => $ticket->code], $ticket);

            return $ticket->fresh();
        });
    }

    public static function spawnSpk(Ticket $ticket): WorkOrder
    {
        self::assertCapability('ticket.spawn_spk');
        abort_if((bool) $ticket->spawned_spk_id, 422, 'SPK already spawned for this ticket.');
        abort_if(! in_array($ticket->status, ['on_progress', 'assigned']), 422, 'Ticket must be on progress or assigned to spawn SPK.');

        return DB::transaction(function () use ($ticket) {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            self::assertCapability('ticket.spawn_spk');
            abort_if((bool) $ticket->spawned_spk_id, 422, 'SPK already spawned for this ticket.');
            abort_if(! in_array($ticket->status, ['on_progress', 'assigned']), 422, 'Ticket must be on progress or assigned to spawn SPK.');
            $wo = WorkOrder::create([
                'code' => NumberSequenceService::generate('spk', $ticket->company_id),
                'type' => 'maintenance',
                'title' => 'SPK from Ticket '.$ticket->code,
                'description' => $ticket->description,
                'status' => 'generated',
                'customer_id' => $ticket->customer_id,
                'subscription_id' => $ticket->subscription_id,
                'location_id' => $ticket->location_id,
                'source' => 'ticket',
                'priority' => $ticket->priority,
                'created_by' => Auth::id(),
            ]);

            $ticket->update(['spawned_spk_id' => $wo->id]);

            AuditService::log('ticket', 'spawned_spk', ['ticket_id' => $ticket->id, 'spk_id' => $wo->id], $ticket);

            return $wo;
        });
    }

    private static function assertCreateReferences(array $data, int $companyId, TicketCategory $category): void
    {
        $customerId = self::lockedCompanyId(Customer::class, $data['customer_id'] ?? null, $companyId);
        $subscription = self::lockedSubscription($data['subscription_id'] ?? null, $companyId);
        $asset = self::lockedAsset($data['network_asset_id'] ?? null, $companyId);
        self::lockedLocation($data['location_id'] ?? null, $companyId);
        abort_unless($category->company_id === $companyId, 404);

        if ($subscription !== null && $customerId !== null) {
            abort_unless((int) $subscription->customer_id === (int) $customerId, 422, 'Subscription does not belong to customer.');
        }

        if ($asset !== null && $customerId !== null && $asset->customer_id !== null) {
            abort_unless((int) $asset->customer_id === (int) $customerId, 422, 'Network asset does not belong to customer.');
        }

        if ($asset !== null && $subscription !== null && $asset->subscription_id !== null) {
            abort_unless((int) $asset->subscription_id === (int) $subscription->id, 422, 'Network asset does not belong to subscription.');
        }
    }

    /**
     * WhatsApp to the ticket's customer, when it has one. The notification is queued after the surrounding
     * transaction commits, so a change that rolls back tells nobody.
     */
    private static function notifyCustomer(Ticket $ticket, string $stage): void
    {
        $customer = $ticket->customer_id === null ? null : Customer::query()->find($ticket->customer_id);

        if ($customer !== null) {
            Notification::send($customer, new TicketCustomerUpdateNotification($ticket->company_id, $ticket->id, $stage));
        }
    }

    private static function lockedCompanyId(string $model, mixed $id, int $companyId): ?int
    {
        $locked = self::lockedModel($model, $id, $companyId);

        return $locked ? (int) $locked->getKey() : null;
    }

    private static function lockedSubscription(mixed $id, int $companyId): ?ServiceSubscription
    {
        /** @var ServiceSubscription|null $subscription */
        $subscription = self::lockedModel(ServiceSubscription::class, $id, $companyId);

        return $subscription;
    }

    private static function lockedAsset(mixed $id, int $companyId): ?NetworkAsset
    {
        /** @var NetworkAsset|null $asset */
        $asset = self::lockedModel(NetworkAsset::class, $id, $companyId);

        return $asset;
    }

    private static function lockedLocation(mixed $id, int $companyId): ?Location
    {
        /** @var Location|null $location */
        $location = self::lockedModel(Location::class, $id, $companyId);

        return $location;
    }

    private static function lockedModel(string $model, mixed $id, int $companyId): ?Model
    {
        if (! $id) {
            return null;
        }

        return $model::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->findOrFail($id);
    }

    private static function lockedTicket(int $ticketId): Ticket
    {
        return Ticket::withoutCompany()
            ->where('company_id', CompanyService::currentId())
            ->lockForUpdate()
            ->findOrFail($ticketId);
    }

    private static function assertCompany(Ticket $ticket): void
    {
        abort_unless($ticket->company_id === CompanyService::currentId(), 404);
    }

    private static function assertCapability(string $ability): void
    {
        abort_unless(Auth::user()?->can($ability) ?? false, 403);
    }

    private static function assertCanonicalExpectedUpdatedAt(mixed $expectedUpdatedAt): void
    {
        abort_unless(
            is_string($expectedUpdatedAt)
            && ($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s.v\\Z', $expectedUpdatedAt)) !== false
            && $parsed->format('Y-m-d\\TH:i:s.v\\Z') === $expectedUpdatedAt,
            422,
            'Expected updated timestamp must use canonical UTC format.'
        );
    }

    private static function throwStatusConflictIfChanged(Ticket $ticket, mixed $expectedStatus, string $requiredStatus, string $message): void
    {
        if ($expectedStatus === $requiredStatus && $ticket->status !== $requiredStatus) {
            throw new ConflictHttpException($message);
        }
    }

    private static function assertAssignmentActor(int $assignedBy, int $companyId, string $ability): void
    {
        $actor = Auth::user();
        abort_unless(
            $actor !== null
            && $actor->is_active
            && (int) $actor->id === $assignedBy
            && CompanyService::idFor($actor) === $companyId
            && $actor->can($ability),
            403
        );
    }

    private static function assertAssignedActor(Ticket $ticket, string $ability): void
    {
        self::assertCapability($ability);
        $actor = Auth::user();
        abort_unless(
            $actor !== null
            && $actor->is_active
            && CompanyService::idFor($actor) === (int) $ticket->company_id
            && $ticket->assigned_to !== null
            && (int) $ticket->assigned_to === (int) $actor->id
            && $actor->can($ability),
            403
        );
    }

    private static function assertCloser(Ticket $ticket): void
    {
        self::assertCapability('ticket.close');
        $actor = Auth::user();
        abort_unless(
            $actor !== null
            && $actor->is_active
            && CompanyService::idFor($actor) === (int) $ticket->company_id,
            403
        );
        // Maker/checker: the handler who resolved the ticket cannot also close it.
        abort_if((int) $ticket->assigned_to === (int) $actor->id, 422, 'Cannot close a ticket you resolved.');
    }
}
