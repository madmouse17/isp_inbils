<?php

namespace Modules\Ticketing\Http\Resources;

use App\Http\Resources\CustomerResource;
use App\Http\Resources\LocationResource;
use App\Http\Resources\MediaResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Core\EmployeeEvaluation;
use App\Support\FieldCheckIn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Modules\NetworkAsset\Http\Resources\NetworkAssetResource;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Services\TicketService;

/** @mixin Ticket */
class TicketResource extends JsonResource
{
    /** @return array<int|string, mixed> */
    public function toArray(Request $request): array
    {
        // The list reads none of the records behind the ids below, and naming each would load one record per row.
        $detail = ! $request->routeIs('admin.tickets.index');
        $show = $request->routeIs('admin.tickets.show');

        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'source' => $this->source,
            'category_id' => $this->category?->public_id,
            'status' => $this->status,
            'priority' => $this->priority,
            'customer_id' => $this->customer?->public_id,
            $this->mergeWhen($detail, fn (): array => [
                'subscription_id' => $this->subscription?->public_id,
                'network_asset_id' => $this->networkAsset?->public_id,
                'location_id' => $this->location?->public_id,
                'spawned_spk_id' => $this->spawnedSpk?->public_id,
                'created_by' => $this->creator?->public_id,
            ]),
            'assigned_to' => $this->assignee?->public_id,
            'sla_deadline' => $this->sla_deadline,
            'response_deadline' => $this->response_deadline,
            'first_response_at' => $this->first_response_at,
            'first_response_source' => $this->first_response_source,
            'resolved_at' => $this->resolved_at,
            'reopened_at' => $this->reopened_at,
            'closed_at' => $this->closed_at,
            'resolution_note' => $this->resolution_note,
            'is_sla_breached' => $this->is_sla_breached,
            'is_response_breached' => $this->is_response_breached,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            // Written when the ticket closes: the page prefers them to the live category and assignee, which follow renames.
            'category_name_snapshot' => $this->category_name_snapshot,
            'assignee_name_snapshot' => $this->assignee_name_snapshot,
            ...$this->lifecycleAttributes(),
            'category' => new TicketCategoryResource($this->whenLoaded('category')),
            'customer' => $this->whenLoaded('customer', fn (): CustomerResource|array => $this->customerFor($request, $detail)),
            'subscription' => new SubscriptionResource($this->whenLoaded('subscription')),
            'network_asset' => new NetworkAssetResource($this->whenLoaded('networkAsset')),
            'location' => new LocationResource($this->whenLoaded('location')),
            // A name is all a page shows of the handler; the full user record would carry roles, 2FA state and policy checks per row.
            'assignee' => $this->whenLoaded('assignee', fn (): array => ['id' => $this->assignee->public_id, 'name' => $this->assignee->name]),
            'comments' => TicketCommentResource::collection($this->whenLoaded('comments')),
            // Show page only: the list does not read them, and a file list costs a media and an uploader query per row.
            'attachments' => $this->when($show, fn () => MediaResource::collection($this->getMedia('attachments'))),
            // Show page only: naming the source loads the subscription's package and tier.
            'deadline_source' => $this->when($show, fn (): array => TicketService::deadlineSource($this->resource)),
            // Show page only: the page words the check-in itself, and the technician's own coordinates stay out of it.
            'check_in' => $this->when($show, fn (): ?array => ($record = FieldCheckIn::recordFor($this->resource, 'ticket')) === null ? null : Arr::only($record, ['status', 'distance_m', 'accuracy_m', 'reason'])),
            // Show page only: lists and exports skip the per-row policy checks. A button shows only for what the click would do.
            'can' => $this->when($show, fn (): array => [
                'start' => (bool) $request->user()?->can('start', $this->resource),
                'close' => (bool) $request->user()?->can('close', $this->resource),
                'resolve' => (bool) $request->user()?->can('resolve', $this->resource),
                'spawn_spk' => $this->mayShowSpawnSpk($request),
                'comment' => $this->mayComment($request),
                'evaluate' => $this->mayEvaluate($request),
            ]),
        ];
    }

    /** The service refuses an SPK for any ticket but an assigned or started one without an SPK yet, so the button follows it. */
    private function mayShowSpawnSpk(Request $request): bool
    {
        return in_array($this->status, ['assigned', 'on_progress'], true)
            && $this->spawned_spk_id === null
            && (bool) $request->user()?->can('spawnSpk', $this->resource);
    }

    /** A closed ticket takes no comment, and the form behind the button asks for the permission to comment. */
    private function mayComment(Request $request): bool
    {
        $user = $request->user();

        return $this->status !== 'closed'
            && $user !== null
            && $user->can('ticket.comment.create')
            && $user->can('comment', $this->resource);
    }

    /**
     * The customer as this viewer may read them. A list row shows a name, so it gets an id, a code and a name. The ticket
     * page also needs the portal account (it tells the customer's comments from staff's) and the phone number the
     * dispatcher calls. The whole record, with the email, tax id and notes, goes only to whoever may open customers.
     *
     * @return CustomerResource|array{id: string, code: string, name: string, user_id?: ?string, phone?: ?string}
     */
    private function customerFor(Request $request, bool $detail): CustomerResource|array
    {
        $customer = $this->customer;
        $summary = ['id' => $customer->public_id, 'code' => $customer->code, 'name' => $customer->name];

        return match (true) {
            ! $detail => $summary,
            (bool) $request->user()?->can('customer.view') => new CustomerResource($customer),
            default => [...$summary, 'user_id' => data_get($customer, 'user.public_id'), 'phone' => $customer->phone],
        };
    }

    /** The Evaluasi form opens from a closed ticket that had a handler, for whoever the form's own page lets in. */
    private function mayEvaluate(Request $request): bool
    {
        return $this->status === 'closed'
            && $this->assigned_to !== null
            && (bool) $request->user()?->can('create', EmployeeEvaluation::class);
    }
}
