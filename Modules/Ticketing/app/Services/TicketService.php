<?php

namespace Modules\Ticketing\Services;

use App\Exceptions\ApiException;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use App\Support\Notifications\StaffRecipients;
use App\Support\OptimisticLock;
use App\Support\RecordNotFound;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Models\TicketComment;
use Modules\Ticketing\Notifications\TicketAssignedNotification;
use Modules\Ticketing\Notifications\TicketCustomerUpdateNotification;
use Modules\Ticketing\Notifications\TicketStaffNotification;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TicketService
{
    private const MISSING_EXPECTED_ASSIGNED_TO = '__missing_expected_assigned_to__';

    private const MISSING_EXPECTED_UPDATED_AT = '__missing_expected_updated_at__';

    /** A ticket is still being worked in these statuses; a file may be withdrawn only until it leaves them. */
    private const OPEN_STATUSES = ['open', 'assigned', 'on_progress'];

    /** Whoever holds one of these can be given a ticket to work: start it, resolve it, or check and close it. */
    public const HANDLER_PERMISSIONS = ['ticket.start', 'ticket.resolve', 'ticket.close'];

    public static function create(array $data, int $createdBy): Ticket
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $companyId = CompanyService::currentId();
            abort_if($companyId === null, 403, 'Konteks perusahaan diperlukan.');

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
            Notification::send(
                StaffRecipients::holding($ticket->company_id, 'ticket.assign', $createdBy),
                new TicketStaffNotification($ticket->company_id, $ticket->id, 'created'),
            );

            return $ticket->fresh();
        });
    }

    /**
     * Saves the fields of the edit form on an open ticket. The status and the version are read from the row under its lock,
     * so a request that waited for an assignment, or a form rendered before another save, changes nothing.
     * A new priority moves both deadlines the way creation sets them.
     *
     * @param  array<string, mixed>  $data
     */
    public static function update(Ticket $ticket, array $data, string $expectedUpdatedAt): Ticket
    {
        return DB::transaction(function () use ($ticket, $data, $expectedUpdatedAt): Ticket {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            abort_if($ticket->status !== 'open', 422, 'Tiket hanya bisa diubah saat berstatus terbuka.');
            OptimisticLock::assert($ticket, $expectedUpdatedAt);

            $ticket->fill($data);
            if ($ticket->isDirty('priority')) {
                $deadlines = self::computeDeadlines($ticket);
                $ticket->fill(['sla_deadline' => $deadlines['resolution'], 'response_deadline' => $deadlines['response']]);
            }
            $ticket->save();

            return $ticket;
        });
    }

    public static function destroy(Ticket $ticket): void
    {
        DB::transaction(function () use ($ticket): void {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            abort_if($ticket->status !== 'closed', 422, 'Tiket hanya bisa dihapus saat berstatus ditutup.');

            $ticket->delete();
        });
    }

    /**
     * When the ticket must get its first response and its resolution, counted in calendar hours from its creation
     * (from now while it is not saved yet).
     * Priority urgent halves both targets, rounded up.
     *
     * @return array{response: ?CarbonImmutable, resolution: CarbonImmutable}
     */
    public static function computeDeadlines(Ticket $ticket): array
    {
        $target = self::slaTarget($ticket);
        $urgent = self::isUrgent($ticket);
        $from = ($ticket->created_at ?? now())->toImmutable();
        $within = static fn (int $hours): CarbonImmutable => $from->addHours($urgent ? (int) ceil($hours / 2) : $hours);

        return [
            'response' => $target['response_hours'] === null ? null : $within($target['response_hours']),
            'resolution' => $within($target['resolution_hours']),
        ];
    }

    /**
     * Where the targets of the ticket come from, as the ticket page words them: an SLA tier or a category, the hours it
     * sets, and whether priority urgent halves them. The hours are the live ones of the tier or category, so a category
     * edited after the ticket was made can differ from the deadline stored on the ticket.
     *
     * @return array{type: 'tier'|'category', name: string, response_hours: ?int, resolution_hours: int, urgent_halved: bool, calendar?: '24h'|'business'}
     */
    public static function deadlineSource(Ticket $ticket): array
    {
        $target = self::slaTarget($ticket);

        return [...$target, 'urgent_halved' => self::isUrgent($ticket)];
    }

    /**
     * The targets a ticket is held to: those of the SLA tier of the package version its subscription was sold under,
     * else those of its category. The version keeps its tier after the package moves on and after the tier is
     * deactivated, since deactivating only stops new sales. Tiers and subscriptions of another company never load,
     * so they never lend their targets.
     *
     * @return array{type: 'tier'|'category', name: string, response_hours: ?int, resolution_hours: int}
     */
    private static function slaTarget(Ticket $ticket): array
    {
        $ticket->loadMissing(['category', 'subscription.packageVersion.slaTier']);
        $tier = $ticket->subscription?->packageVersion?->slaTier;

        if ($tier !== null) {
            return ['type' => 'tier', 'name' => $tier->name, 'response_hours' => $tier->response_time_hours, 'resolution_hours' => $tier->resolution_time_hours];
        }

        return [
            'type' => 'category',
            'name' => $ticket->category->name,
            'response_hours' => $ticket->category->default_response_hours,
            'resolution_hours' => $ticket->category->default_sla_hours,
        ];
    }

    /** Priority urgent, or no priority yet on a category whose default is urgent. Expects the category loaded. */
    private static function isUrgent(Ticket $ticket): bool
    {
        return ($ticket->priority ?? $ticket->category->default_priority) === 'urgent';
    }

    public static function assign(Ticket $ticket, int $handlerId, int $assignedBy, mixed $expectedAssignedTo = self::MISSING_EXPECTED_ASSIGNED_TO, mixed $expectedUpdatedAt = self::MISSING_EXPECTED_UPDATED_AT): Ticket
    {
        return DB::transaction(function () use ($ticket, $handlerId, $assignedBy, $expectedAssignedTo, $expectedUpdatedAt) {
            abort_if(
                $expectedAssignedTo === self::MISSING_EXPECTED_ASSIGNED_TO
                || $expectedUpdatedAt === self::MISSING_EXPECTED_UPDATED_AT,
                422,
                'Status penugasan semula wajib dikirim.'
            );
            abort_unless(
                $expectedAssignedTo === null || is_int($expectedAssignedTo),
                422,
                'Penanggung jawab semula harus berupa angka atau kosong.'
            );
            self::assertCanonicalExpectedUpdatedAt($expectedUpdatedAt);
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            abort_if(! in_array($ticket->status, ['open', 'assigned']), 422, 'Tiket harus berstatus terbuka atau ditugaskan untuk bisa ditugaskan.');

            $ability = $ticket->assigned_to === null ? 'ticket.assign' : 'ticket.reassign';
            self::assertAssignmentActor($assignedBy, $ticket->company_id, $ability);

            if ($expectedAssignedTo === null) {
                $assignmentMatches = $ticket->assigned_to === null;
            } else {
                $assignmentMatches = (int) $ticket->assigned_to === (int) $expectedAssignedTo;
            }

            if (! $assignmentMatches) {
                abort(409, 'Konflik penugasan: tiket ini sudah ditugaskan oleh pengguna lain.');
            }

            $canonicalUpdatedAt = $ticket->updated_at?->utc()->format('Y-m-d\\TH:i:s.v\\Z');
            if ($expectedUpdatedAt === null || $canonicalUpdatedAt !== $expectedUpdatedAt) {
                throw new ConflictHttpException(
                    'Konflik penugasan: tiket ini sudah ditugaskan oleh pengguna lain.',
                    null,
                    409
                );
            }

            $companySettings = data_get($ticket->company, 'settings', []);
            if (($companySettings['forbid_self_assignment'] ?? false) && (int) $assignedBy === (int) $handlerId) {
                abort(422, 'Kebijakan perusahaan melarang menugaskan diri sendiri.');
            }

            $handler = StaffRecipients::members((int) $ticket->company_id)
                ->withPermissionIn(self::HANDLER_PERMISSIONS, (int) $ticket->company_id)
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

    /**
     * "Tugaskan ke saya": an unassigned ticket goes to the actor. Read under the lock, so a ticket that somebody else took
     * since the page was drawn is a 409 and never a quiet reassignment; the rest is the assignment of the dialog.
     */
    public static function assignSelf(Ticket $ticket, User $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $actor): Ticket {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);

            if ($ticket->assigned_to !== null) {
                throw new ConflictHttpException('Konflik penugasan: tiket ini sudah ditugaskan oleh pengguna lain.', null, 409);
            }

            return self::assign($ticket, $actor->id, $actor->id, null, $ticket->updated_at?->utc()->format('Y-m-d\\TH:i:s.v\\Z'));
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
            self::throwStatusConflictIfChanged($ticket, $expectedStatus, 'assigned', 'Konflik: status tiket sudah diubah oleh pengguna lain.');
            abort_if($ticket->status !== 'assigned', 422, 'Tiket harus berstatus ditugaskan untuk bisa dimulai.');
            // A public reply may have answered the customer before the handler pressed Mulai: then that reply stays the first response.
            $firstResponse = $ticket->first_response_at === null ? ['first_response_at' => now(), 'first_response_source' => 'start'] : [];
            $ticket->update(['status' => 'on_progress', ...$firstResponse]);

            AuditService::log('ticket', 'started', ['code' => $ticket->code], $ticket);

            self::notifyCustomer($ticket, 'started');

            return $ticket->fresh();
        });
    }

    /**
     * A comment by staff. A closed ticket takes none. The first public one is the first response, unless Mulai came first,
     * and the customer hears of every public one. An internal note does neither.
     *
     * @param  array{body: string, is_internal?: bool}  $data
     */
    public static function addComment(Ticket $ticket, array $data, int $authorId): TicketComment
    {
        self::assertCompany($ticket);

        return DB::transaction(function () use ($ticket, $data, $authorId): TicketComment {
            $ticket = self::lockedTicket($ticket->id);
            abort_if($ticket->status === 'closed', 422, 'Tiket sudah ditutup.');

            $internal = (bool) ($data['is_internal'] ?? false);
            $comment = TicketComment::create([
                'ticket_id' => $ticket->id,
                'author_id' => $authorId,
                'body' => $data['body'],
                'is_internal' => $internal,
            ]);

            if (! $internal) {
                if ($ticket->first_response_at === null) {
                    $ticket->update(['first_response_at' => now(), 'first_response_source' => 'reply']);
                }

                self::notifyCustomer($ticket, 'replied', $authorId);
            }

            return $comment;
        });
    }

    /**
     * The customer's reply from the app. A closed ticket takes none; a resolved one comes back to its handler (to dispatch
     * when it has none). Either way the handler and dispatch hear of it. It is never a response by staff.
     *
     * @param  array{body: string}  $data
     */
    public static function addCustomerReply(Ticket $ticket, array $data, User $customerUser): TicketComment
    {
        self::assertCompany($ticket);

        return DB::transaction(function () use ($ticket, $data, $customerUser): TicketComment {
            $ticket = self::lockedTicket($ticket->id);
            self::assertOpenForCustomer($ticket);

            $comment = TicketComment::create([
                'ticket_id' => $ticket->id,
                'author_id' => $customerUser->id,
                'body' => $data['body'],
                'is_internal' => false,
            ]);

            $reopens = $ticket->status === 'resolved';
            if ($reopens) {
                self::reopen($ticket, 'customer');
            }

            Notification::send(
                self::staffWatching($ticket),
                new TicketStaffNotification($ticket->company_id, $ticket->id, $reopens ? 'reopened' : 'customer_replied'),
            );

            return $comment;
        });
    }

    /**
     * Staff bring a resolved or closed ticket back to work and say why (K13). The handler hears of it (dispatch, when the ticket
     * has none or the handler is gone) and the customer is told that it is being worked on again. Whoever reopened it is not
     * told of their own act.
     */
    public static function reopenByStaff(Ticket $ticket, User $actor, string $reason): Ticket
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($ticket, $actor, $reason): Ticket {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);

            // The page that offered the button may be older than the ticket: only a finished ticket comes back.
            if (! in_array($ticket->status, Ticket::FINISHED_STATUSES, true)) {
                throw ValidationException::withMessages(['status' => 'Hanya tiket yang diselesaikan atau ditutup yang bisa dibuka kembali.']);
            }

            $reopening = self::reopen($ticket, 'staff', $reason, $actor);

            $handler = self::currentHandler($ticket);
            $watchers = $handler === null
                ? StaffRecipients::holding((int) $ticket->company_id, 'ticket.assign', $actor->id)
                : collect([$handler])->reject(fn (User $user): bool => $user->is($actor));

            // The notice reads who and why from the audit row when it is sent: the queued payload keeps ids only.
            Notification::send($watchers, new TicketStaffNotification($ticket->company_id, $ticket->id, 'reopened', 'staff', $reopening instanceof Model ? (int) $reopening->getKey() : null));
            self::notifyCustomer($ticket, 'reopened');

            return $ticket->fresh();
        });
    }

    /**
     * A finished ticket goes back to work: assigned when it still has a handler, else open. The resolution and the closing
     * leave the ticket and stay in the audit row; the targets are not restarted and a rating the customer gave stays.
     * Expects the ticket row locked by the caller.
     *
     * @param  string  $by  'customer' (a reply on a resolved ticket) or 'staff'
     * @param  ?User  $actor  who reopened it, for the audit row; none: the signed-in user
     * @return ?ActivityContract the audit row of the reopening
     */
    public static function reopen(Ticket $ticket, string $by, ?string $reason = null, ?User $actor = null): ?ActivityContract
    {
        $previousStatus = $ticket->status;
        $resolutionNote = $ticket->resolution_note;

        $ticket->update([
            'status' => $ticket->assigned_to === null ? 'open' : 'assigned',
            'resolved_at' => null,
            'resolution_note' => null,
            'closed_at' => null,
            // A closed ticket keeps the names it closed with; open again, it follows the live records.
            'category_name_snapshot' => null,
            'assignee_name_snapshot' => null,
            'reopened_at' => now(),
        ]);

        return AuditService::log('ticket', 'reopened', [
            'previous_status' => $previousStatus,
            'resolution_note' => $resolutionNote,
            'by' => $by,
            ...($reason === null ? [] : ['reason' => $reason]),
        ], $ticket, causer: $actor);
    }

    /**
     * Whoever must hear that something reached a ticket: its handler while active, and dispatch (the holders of
     * ticket.assign), each once.
     *
     * @return EloquentCollection<int, User>
     */
    public static function staffWatching(Ticket $ticket): EloquentCollection
    {
        $dispatch = StaffRecipients::holding((int) $ticket->company_id, 'ticket.assign');
        $handler = self::currentHandler($ticket);

        return $handler === null ? $dispatch : $dispatch->push($handler)->unique('id')->values();
    }

    /**
     * The handler of a ticket, while they are an active member of its company. `assigned_to` stays when a membership is
     * revoked, and a user homed in another company must not hear what happens in this one.
     */
    private static function currentHandler(Ticket $ticket): ?User
    {
        return $ticket->assigned_to === null ? null : StaffRecipients::members((int) $ticket->company_id)->find($ticket->assigned_to);
    }

    /**
     * A ticket as the app user may reach it: any ticket of the company for a technician (the policy then narrows
     * it to the assigned handler), only the customer's own for a customer, so a stranger's id is a plain 404.
     */
    public static function findForApp(string $publicId, ?Customer $customer): Ticket
    {
        return Ticket::query()
            ->when($customer, fn (Builder $query, Customer $own): Builder => $query->where('customer_id', $own->id))
            ->wherePublicId($publicId)
            ->firstOrFail();
    }

    /** The web's rules for attaching: the permission, plus the ticket policy (company, assignment or ownership). */
    public static function mayAttach(User $user, Ticket $ticket): bool
    {
        return $user->can('ticket.attachment.upload')
            && $user->can('view', $ticket)
            && $user->can('attach', $ticket);
    }

    /** A ticket the policy hides from the user is a 404; one they see but may not attach to is a 403. */
    public static function assertMayAttach(User $user, Ticket $ticket): void
    {
        Gate::forUser($user)->authorize('view', $ticket);
        abort_unless(self::mayAttach($user, $ticket), 403);
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
     * response cache still finds the file it already stored instead of adding a second one. A customer's upload
     * is refused once the ticket is closed, like their reply.
     *
     * @param  array{file: UploadedFile, caption?: ?string}  $data
     */
    public static function addAttachment(Ticket $ticket, User $actor, array $data, ?string $idempotencyKey = null, bool $asCustomer = false): Media
    {
        return DB::transaction(function () use ($ticket, $actor, $data, $idempotencyKey, $asCustomer): Media {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            self::assertMayAttach($actor, $ticket);
            if ($asCustomer) {
                self::assertOpenForCustomer($ticket);
            }

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
            DB::afterRollBack(fn () => $media->delete());

            AuditService::log('ticket', 'attachment_uploaded', ['media_id' => $media->id, 'uploaded_by' => $actor->id], $ticket);

            return $media;
        });
    }

    public static function removeAttachment(Ticket $ticket, string $mediaPublicId, User $actor): void
    {
        DB::transaction(function () use ($ticket, $mediaPublicId, $actor): void {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            self::assertMayAttach($actor, $ticket);
            $media = $ticket->getMedia('attachments')->first(fn (Media $item): bool => $item->public_id === strtolower($mediaPublicId));
            RecordNotFound::abortUnless($media instanceof Media);
            abort_unless(self::mayRemoveAttachment($actor, $ticket, $media), 403);

            $media->delete();

            AuditService::log('ticket', 'attachment_removed', ['media_id' => $media->id, 'removed_by' => $actor->id], $ticket);
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
            self::throwStatusConflictIfChanged($ticket, $expectedStatus, 'on_progress', 'Konflik: status tiket sudah diubah oleh pengguna lain.');
            abort_if($ticket->status !== 'on_progress', 422, 'Tiket harus berstatus dikerjakan untuk bisa diselesaikan.');
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
        abort_if($ticket->status !== 'resolved', 422, 'Tiket harus berstatus diselesaikan untuk bisa ditutup.');

        return DB::transaction(function () use ($ticket, $expectedStatus) {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            self::assertCloser($ticket);
            self::throwStatusConflictIfChanged($ticket, $expectedStatus, 'resolved', 'Konflik: status tiket sudah diubah oleh pengguna lain.');
            abort_if($ticket->status !== 'resolved', 422, 'Tiket harus berstatus diselesaikan untuk bisa ditutup.');
            self::closeLocked($ticket);

            self::notifyCustomer($ticket, 'closed');

            return $ticket->fresh();
        });
    }

    /**
     * Closes a resolved ticket whose row the caller holds locked, and keeps the names it closes with. $also goes into the same
     * write (the rating, when the customer closes the ticket). Who may close, and whether the status allows it, is the caller's call.
     *
     * @param  array<string, mixed>  $also
     */
    private static function closeLocked(Ticket $ticket, array $also = []): void
    {
        // Read under the lock, with the status: a rename after this moment never reaches the closed ticket.
        $ticket->loadMissing(['category', 'assignee']);
        $ticket->update([
            'status' => 'closed',
            'closed_at' => now(),
            'category_name_snapshot' => $ticket->category->name,
            'assignee_name_snapshot' => $ticket->assignee?->name,
            ...$also,
        ]);

        AuditService::log('ticket', 'closed', ['code' => $ticket->code], $ticket);
    }

    /**
     * The customer's answer to "Masalah sudah beres?" (K14): a resolved ticket closes with the rating, a closed one that was never
     * rated only takes it. A ticket is rated once, and the rating survives a reopening, so a ticket rated before it came back is
     * not rated again. Nothing but a finished ticket is rated. Whose ticket it is, is the policy's to say.
     */
    public static function confirmByCustomer(Ticket $ticket, int $rating, ?string $comment): Ticket
    {
        self::assertCompany($ticket);

        return DB::transaction(function () use ($ticket, $rating, $comment): Ticket {
            $ticket = self::lockedTicket($ticket->id);

            if (! in_array($ticket->status, Ticket::FINISHED_STATUSES, true)) {
                throw new ApiException(422, 'ticket_not_resolved', 'Tiket belum diselesaikan.');
            }

            if ($ticket->customer_rated_at !== null) {
                throw new ApiException(409, 'ticket_already_rated', 'Tiket ini sudah dinilai.');
            }

            $rated = ['customer_rating' => $rating, 'customer_rating_comment' => $comment, 'customer_rated_at' => now()];
            $closes = $ticket->status === 'resolved';

            if ($closes) {
                self::closeLocked($ticket, $rated);
            } else {
                $ticket->update($rated);
            }

            AuditService::log('ticket', 'customer_confirmed', ['rating' => $rating, 'closed' => $closes], $ticket);

            return $ticket->fresh();
        });
    }

    public static function spawnSpk(Ticket $ticket): WorkOrder
    {
        self::assertCapability('ticket.spawn_spk');
        abort_if((bool) $ticket->spawned_spk_id, 422, 'SPK sudah dibuat dari tiket ini.');
        abort_if(! in_array($ticket->status, ['on_progress', 'assigned']), 422, 'Tiket harus berstatus dikerjakan atau ditugaskan untuk bisa dibuatkan SPK.');

        return DB::transaction(function () use ($ticket) {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            self::assertCapability('ticket.spawn_spk');
            abort_if((bool) $ticket->spawned_spk_id, 422, 'SPK sudah dibuat dari tiket ini.');
            abort_if(! in_array($ticket->status, ['on_progress', 'assigned']), 422, 'Tiket harus berstatus dikerjakan atau ditugaskan untuk bisa dibuatkan SPK.');
            $wo = WorkOrder::create([
                'code' => NumberSequenceService::generate('spk', $ticket->company_id),
                'type' => 'maintenance',
                'title' => 'SPK dari tiket '.$ticket->code,
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
            abort_unless((int) $subscription->customer_id === (int) $customerId, 422, 'Langganan bukan milik pelanggan yang dipilih.');
        }

        if ($asset !== null && $customerId !== null && $asset->customer_id !== null) {
            abort_unless((int) $asset->customer_id === (int) $customerId, 422, 'Aset jaringan bukan milik pelanggan yang dipilih.');
        }

        if ($asset !== null && $subscription !== null && $asset->subscription_id !== null) {
            abort_unless((int) $asset->subscription_id === (int) $subscription->id, 422, 'Aset jaringan bukan milik langganan yang dipilih.');
        }
    }

    /**
     * The ticket's customer hears of a stage in the bell and on the phone, and on WhatsApp for the stages that have a
     * template. The notification is queued after the surrounding transaction commits, so a change that rolls back
     * tells nobody. Whoever caused the stage is not told of their own act.
     */
    private static function notifyCustomer(Ticket $ticket, string $stage, ?int $exceptUserId = null): void
    {
        $customer = $ticket->customer_id === null ? null : Customer::query()->find($ticket->customer_id);

        if ($customer !== null && ($exceptUserId === null || (int) $customer->user_id !== $exceptUserId)) {
            Notification::send($customer, new TicketCustomerUpdateNotification($ticket->company_id, $ticket->id, $stage));
        }
    }

    /** A closed ticket takes nothing more from its customer, who is pointed to a new one. */
    private static function assertOpenForCustomer(Ticket $ticket): void
    {
        if ($ticket->status === 'closed') {
            throw new ApiException(422, 'ticket_closed', 'Tiket ditutup. Masih bermasalah? Buat tiket baru.');
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
        RecordNotFound::abortUnless($ticket->company_id === CompanyService::currentId());
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
            'Waktu pembaruan semula harus berformat UTC baku.'
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
        abort_unless($actor !== null && $actor->is_active, 403);
        // Another handler's ticket, or another company's, is a record the caller cannot see, not a refused permission.
        RecordNotFound::abortUnless(
            CompanyService::idFor($actor) === (int) $ticket->company_id
            && $ticket->assigned_to !== null
            && (int) $ticket->assigned_to === (int) $actor->id
        );
    }

    private static function assertCloser(Ticket $ticket): void
    {
        self::assertCapability('ticket.close');
        $actor = Auth::user();
        abort_unless($actor !== null && $actor->is_active, 403);
        RecordNotFound::abortUnless(CompanyService::idFor($actor) === (int) $ticket->company_id);
        // Maker/checker: the handler who resolved the ticket cannot also close it.
        abort_if((int) $ticket->assigned_to === (int) $actor->id, 422, 'Tiket yang Anda selesaikan tidak bisa Anda tutup sendiri.');
    }
}
