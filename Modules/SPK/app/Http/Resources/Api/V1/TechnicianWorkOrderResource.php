<?php

namespace Modules\SPK\Http\Resources\Api\V1;

use App\Models\User;
use App\Services\WhatsApp\PhoneNumber;
use App\Support\FieldCheckIn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Services\SpkService;
use Modules\SPK\Support\TripSteps;
use Modules\SPK\Support\WorkOrderSite;

/**
 * What the technician app may see of an SPK: an allow-list, never the admin resource.
 *
 * @property WorkOrder $resource
 */
class TechnicianWorkOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $wo = $this->resource;
        /** @var User|null $user */
        $user = $request->user();
        $phone = PhoneNumber::normalizeIndonesianMobile($wo->customer?->phone);

        return [
            'id' => $wo->public_id,
            'code' => $wo->code,
            'type' => $wo->type,
            /** The type in Indonesian, as the app shows it: never show `type` itself. */
            'type_label' => $wo->typeLabel(),
            'title' => $wo->title,
            'description' => $wo->description,
            'status' => $wo->status,
            'priority' => $wo->priority,
            'scheduled_date' => $wo->scheduled_date?->toDateString(),
            /**
             * The four steps of the visit. `current` is the last one reached: it and the steps before it are reached and only
             * those carry a time, which is null for a step that was skipped. Empty for a cancelled SPK.
             *
             * @var list<array{key: 'assigned'|'en_route'|'in_progress'|'installed', label: string, at: string|null}>
             */
            'steps' => TripSteps::of($wo),
            /** The last step reached; null when none has (a draft, a waiting SPK, a cancelled one). */
            'current' => TripSteps::current($wo),
            'started_at' => $wo->started_at,
            'rejection_reason' => $wo->rejection_reason,
            'check_in' => FieldCheckIn::apiSummary($wo, 'work_order'),
            'ticket' => $this->ticketBlock(),
            'customer' => $wo->customer === null ? null : [
                'id' => $wo->customer->public_id,
                'code' => $wo->customer->code,
                'name' => $wo->customer->name,
                'phone' => $wo->customer->phone,
                /** The phone as `+62…` for a WhatsApp link (`https://wa.me/` without the plus); null when it is not an Indonesian mobile number. */
                'phone_e164' => $phone === null ? null : '+'.$phone,
            ],
            'subscription' => $wo->subscription === null ? null : [
                'id' => $wo->subscription->public_id,
                'code' => $wo->subscription->code,
                'package_name' => $wo->subscription->servicePackage?->name,
            ],
            /**
             * The PPPoE login of an installation, for the technician it is assigned to: the username and where it stands,
             * never the secret (the app asks for that behind "Tampilkan", POST network-credentials). Null for any other
             * type, while no account is made, and for anyone but the assignee.
             *
             * @var array{username: string, sync_status: 'pending'|'synced'|'error', can_reveal: bool}|null
             */
            'network_account' => $this->networkAccount($user),
            /** Where to go: the customer's installation address for a subscription SPK, else the SPK's location. */
            'site' => WorkOrderSite::site($wo),
            /** The POP the SPK is stocked from, apart from the site; null when the site is the location itself. */
            'pop' => WorkOrderSite::pop($wo),
            'items' => TechnicianWorkOrderItemResource::collection($wo->items),
            'evidence' => $user === null ? [] : EvidenceResource::many($wo->getMedia('evidence'), $user),
            'material_returns' => $this->materialReturns(),
            'actions' => [
                'start' => $user?->can('start', $wo) ?? false,
                /**
                 * "Berangkat" is offered once: after it the next action is the start.
                 *
                 * @var bool
                 */
                'en_route' => ($user?->can('enRoute', $wo) ?? false) && $wo->en_route_at === null,
                'upload_evidence' => $user?->can('uploadEvidence', $wo) ?? false,
                'submit' => $user?->can('submit', $wo) ?? false,
                // Whoever may submit the SPK (its assigned technician, while it is in progress) keeps its material lines.
                'edit_items' => $user?->can('submit', $wo) ?? false,
            ],
        ];
    }

    /** @return array{username: string, sync_status: 'pending'|'synced'|'error', can_reveal: bool}|null */
    private function networkAccount(?User $user): ?array
    {
        $wo = $this->resource;

        if ($user === null || $wo->type !== 'installation' || $wo->subscription_id === null || (int) $wo->assigned_to !== (int) $user->id) {
            return null;
        }

        // The columns are named: the secret is not even loaded.
        $account = NetworkAccount::query()
            ->where('subscription_id', $wo->subscription_id)
            ->where('service_type', 'pppoe')
            ->first(['id', 'subscription_id', 'username', 'sync_status']);

        if ($account === null) {
            return null;
        }

        return [
            'username' => $account->username,
            'sync_status' => $account->sync_status === 'failed' ? 'error' : $account->sync_status,
            'can_reveal' => in_array($wo->status, SpkService::CREDENTIAL_STATUSES, true)
                && $wo->subscription !== null
                && ! $wo->subscription->trashed()
                && $wo->subscription->status !== 'terminated',
        ];
    }

    /** @return array{id: string, code: string|null}|null */
    private function ticketBlock(): ?array
    {
        if ($this->resource->ticket_id === null) {
            return null;
        }

        $ticket = DB::table('tickets')
            ->where('company_id', $this->resource->company_id)
            ->where('id', $this->resource->ticket_id)
            ->whereNull('deleted_at')
            ->first(['public_id', 'code']);

        return $ticket === null ? null : ['id' => $ticket->public_id, 'code' => $ticket->code];
    }

    /** @return list<array{id: string, status: string}> */
    private function materialReturns(): array
    {
        return MaterialReturnCase::withoutCompany()
            ->where('company_id', $this->resource->company_id)
            ->whereHas('inventoryRequest', function ($query): void {
                $query->where('source_type', 'work_order')->where('source_id', $this->resource->id);
            })
            ->orderBy('id')
            ->get(['id', 'public_id', 'status'])
            ->map(fn (MaterialReturnCase $case): array => ['id' => $case->public_id, 'status' => $case->status])
            ->values()
            ->all();
    }
}
