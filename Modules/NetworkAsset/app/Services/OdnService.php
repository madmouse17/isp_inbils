<?php

namespace Modules\NetworkAsset\Services;

use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\OdnSplitter;
use Modules\NetworkAsset\Support\OdnLock;

/**
 * The optical distribution network as data: PON ports on OLTs, splitters in ODCs and ODPs, and the ODP
 * output port each customer drop uses. A passive register: it never talks to equipment.
 *
 * Lock order is always subscription, then ports (and a splitter before its ports); a port row is the mutex
 * of everything that attaches to it, so reads of "is this port free" happen after its lock.
 *
 * Every change to the fibre graph (ports, splitters, connections) runs under OdnLock: one at a time per company,
 * in a transaction that opens after the lock is taken. The cycle walk also reads splitters with a shared lock (a
 * current read), so a connection another process just committed is seen even by a transaction whose snapshot is older.
 */
class OdnService
{
    /** Chains deeper than this are refused, which keeps the upward cycle walk bounded and exact. */
    private const MAX_HOPS = 8;

    /** @var list<string> a drop belongs to a subscription that is, or will be, in service */
    private const DROP_STATUSES = ['pending', 'active', 'suspended'];

    /**
     * @return Collection<int, OdnPort> the new ports, numbered after the highest existing one
     */
    public static function createPonPorts(NetworkAsset $olt, int $count): Collection
    {
        $companyId = self::companyId();

        if ($count < 1 || $count > 64) {
            throw ValidationException::withMessages(['count' => 'Create between 1 and 64 PON ports at a time.']);
        }

        return OdnLock::run($companyId, function () use ($olt, $count): Collection {
            $olt = self::lockAsset($olt->id);

            if ($olt->asset_type !== 'olt') {
                throw ValidationException::withMessages(['asset' => 'PON ports can only be created on an OLT.']);
            }

            $highest = (int) OdnPort::query()->where('network_asset_id', $olt->id)->where('kind', OdnPort::KIND_PON)->max('number');
            $rows = [];

            for ($number = $highest + 1; $number <= $highest + $count; $number++) {
                $rows[] = self::portRow($olt, OdnPort::KIND_PON, $number, null);
            }

            OdnPort::query()->insert($rows);

            AuditService::log('odn', 'pon_ports_created', ['asset_id' => $olt->id, 'from' => $highest + 1, 'to' => $highest + $count], $olt);

            return OdnPort::query()
                ->where('network_asset_id', $olt->id)
                ->where('kind', OdnPort::KIND_PON)
                ->where('number', '>', $highest)
                ->orderBy('number')
                ->get();
        });
    }

    /** A splitter in an ODC or ODP with its N output ports, optionally fed by a free upstream port. */
    public static function createSplitter(NetworkAsset $host, int $outputs, OdnPort|int|null $input = null, ?string $label = null): OdnSplitter
    {
        $companyId = self::companyId();

        if (! in_array($outputs, OdnSplitter::OUTPUTS, true)) {
            throw ValidationException::withMessages(['outputs' => 'A splitter has 2, 4, 8, 16, 32 or 64 outputs.']);
        }

        return OdnLock::run($companyId, function () use ($host, $outputs, $input, $label): OdnSplitter {
            $host = self::lockAsset($host->id);

            if (! in_array($host->asset_type, ['odc', 'odp'], true)) {
                throw ValidationException::withMessages(['asset' => 'Splitters sit in an ODC or an ODP.']);
            }

            $inputPort = $input === null ? null : self::lockFreePort(self::idOf($input), 'input_port_id');

            if ($inputPort !== null) {
                self::assertNotDownstream(null, $inputPort);
            }

            $splitter = OdnSplitter::query()->create([
                'network_asset_id' => $host->id,
                'outputs' => $outputs,
                'input_port_id' => $inputPort?->id,
                'label' => $label,
            ]);

            $rows = [];

            for ($number = 1; $number <= $outputs; $number++) {
                $rows[] = self::portRow($host, OdnPort::KIND_SPLITTER_OUT, $number, $splitter->id);
            }

            OdnPort::query()->insert($rows);

            AuditService::log('odn', 'splitter_created', [
                'asset_id' => $host->id,
                'outputs' => $outputs,
                'input_port_id' => $inputPort?->id,
            ], $splitter);

            return $splitter;
        });
    }

    /** Connects the splitter's input to a free port (never one downstream of itself), or disconnects it with null. */
    public static function connectSplitter(OdnSplitter|int $splitter, OdnPort|int|null $input): OdnSplitter
    {
        return OdnLock::run(self::companyId(), fn (): OdnSplitter => self::connect($splitter, $input));
    }

    /** The body of connectSplitter, for callers that already hold the topology lock and its transaction. */
    private static function connect(OdnSplitter|int $splitter, OdnPort|int|null $input): OdnSplitter
    {
        $locked = OdnSplitter::query()->lockForUpdate()->findOrFail(self::idOf($splitter));
        $inputId = $input === null ? null : self::idOf($input);

        if ($inputId === $locked->input_port_id) {
            return $locked;
        }

        if ($inputId !== null) {
            $port = self::lockFreePort($inputId, 'input_port_id');
            self::assertNotDownstream($locked, $port);
        }

        $locked->update(['input_port_id' => $inputId]);

        AuditService::log('odn', $inputId === null ? 'splitter_disconnected' : 'splitter_connected', [
            'splitter_id' => $locked->id,
            'input_port_id' => $inputId,
        ], $locked);

        return $locked;
    }

    /**
     * Applies the keys that are present: input_port_id (null disconnects) and label.
     *
     * @param  array{input_port_id?: int|null, label?: string|null}  $data
     */
    public static function updateSplitter(OdnSplitter|int $splitter, array $data): OdnSplitter
    {
        return OdnLock::run(self::companyId(), function () use ($splitter, $data): OdnSplitter {
            $current = OdnSplitter::query()->findOrFail(self::idOf($splitter));

            if (array_key_exists('input_port_id', $data)) {
                $current = self::connect($current, $data['input_port_id']);
            }

            if (array_key_exists('label', $data)) {
                $current->update(['label' => $data['label']]);
            }

            return $current;
        });
    }

    /** Deletes a splitter and its outputs; refused while a drop or a downstream splitter hangs on them. */
    public static function deleteSplitter(OdnSplitter|int $splitter): void
    {
        OdnLock::run(self::companyId(), function () use ($splitter): void {
            $locked = OdnSplitter::query()->lockForUpdate()->findOrFail(self::idOf($splitter));
            $ports = OdnPort::query()->where('odn_splitter_id', $locked->id)->lockForUpdate()->get();

            $attached = $ports->contains(fn (OdnPort $port): bool => $port->subscription_id !== null)
                || OdnSplitter::query()->whereIn('input_port_id', $ports->modelKeys())->lockForUpdate()->exists();

            if ($attached) {
                throw ValidationException::withMessages(['splitter' => 'Something is still connected to its outputs. Release the drops and disconnect downstream splitters first.']);
            }

            OdnPort::query()->where('odn_splitter_id', $locked->id)->delete();
            $locked->delete();

            AuditService::log('odn', 'splitter_deleted', ['asset_id' => $locked->network_asset_id, 'outputs' => $locked->outputs], $locked);
        });
    }

    /** Only a PON port can be deleted on its own (a splitter owns its outputs), and not while a splitter hangs on it. */
    public static function deletePort(OdnPort|int $port): void
    {
        OdnLock::run(self::companyId(), function () use ($port): void {
            $locked = OdnPort::query()->lockForUpdate()->findOrFail(self::idOf($port));

            if ($locked->kind !== OdnPort::KIND_PON) {
                throw ValidationException::withMessages(['port' => 'Only PON ports can be deleted. Delete the splitter to remove its outputs.']);
            }

            if (OdnSplitter::query()->where('input_port_id', $locked->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['port' => 'A splitter is connected to this PON port. Disconnect it first.']);
            }

            $locked->delete();

            AuditService::log('odn', 'pon_port_deleted', ['asset_id' => $locked->network_asset_id, 'number' => $locked->number], $locked);
        });
    }

    /**
     * Records the ODP output port a subscription's drop uses. A subscription has one drop: giving it another
     * port frees the first in the same transaction. $portField names the input the port came from, so the
     * 422 lands on the right form field.
     */
    public static function assignDrop(OdnPort|int $port, ServiceSubscription|int $subscription, string $portField = 'port'): OdnPort
    {
        self::companyId();

        return DB::transaction(function () use ($port, $subscription, $portField): OdnPort {
            $holder = ServiceSubscription::query()->lockForUpdate()->find(self::idOf($subscription));

            if ($holder === null) {
                throw ValidationException::withMessages(['subscription_id' => 'The subscription does not exist.']);
            }

            if (! in_array($holder->status, self::DROP_STATUSES, true)) {
                throw ValidationException::withMessages(['subscription_id' => "Subscription {$holder->code} is {$holder->status} and cannot take a drop."]);
            }

            $target = OdnPort::query()->lockForUpdate()->find(self::idOf($port));

            if ($target === null) {
                throw ValidationException::withMessages([$portField => 'The selected port does not exist.']);
            }

            $odp = NetworkAsset::query()->find($target->network_asset_id);

            if ($target->kind !== OdnPort::KIND_SPLITTER_OUT || $odp === null || $odp->asset_type !== 'odp') {
                throw ValidationException::withMessages([$portField => 'A customer drop goes on an output port of an ODP.']);
            }

            if ($target->subscription_id === $holder->id) {
                return $target;
            }

            if (self::isOccupied($target)) {
                throw ValidationException::withMessages([$portField => 'This port is already in use.']);
            }

            self::assertUnderServingPop($holder, $odp, $portField);

            $previous = OdnPort::query()->where('subscription_id', $holder->id)->first();
            $previous = $previous === null ? null : OdnPort::query()->lockForUpdate()->find($previous->id);

            if ($previous !== null && $previous->subscription_id === $holder->id) {
                $previous->update(['subscription_id' => null]);
            }

            $target->update(['subscription_id' => $holder->id]);

            AuditService::log('odn', 'drop_assigned', [
                'subscription_id' => $holder->id,
                'port_id' => $target->id,
                'odp_asset_id' => $odp->id,
                'moved_from_port_id' => $previous?->id,
            ], $target);

            return $target;
        }, 3);
    }

    public static function releaseDrop(OdnPort|int $port): void
    {
        self::companyId();

        DB::transaction(function () use ($port): void {
            $locked = OdnPort::query()->lockForUpdate()->findOrFail(self::idOf($port));

            if ($locked->subscription_id === null) {
                throw ValidationException::withMessages(['port' => 'This port has no drop to release.']);
            }

            self::clearDrop($locked, 'released');
        });
    }

    /** Frees the subscription's drop port, if it has one; returns whether it did. */
    public static function releaseForSubscription(ServiceSubscription $subscription, string $reason = 'subscription terminated'): bool
    {
        return DB::transaction(function () use ($subscription, $reason): bool {
            ServiceSubscription::query()->withoutCompany()->whereKey($subscription->id)->lockForUpdate()->value('id');

            $held = OdnPort::query()->forCompany((int) $subscription->company_id)->where('subscription_id', $subscription->id)->first();
            $port = $held === null ? null : OdnPort::query()->forCompany((int) $subscription->company_id)->lockForUpdate()->find($held->id);

            if ($port === null || $port->subscription_id !== $subscription->id) {
                return false;
            }

            self::clearDrop($port, $reason);

            return true;
        });
    }

    /**
     * The drop port of a subscription as the pages show it, or null.
     *
     * @return array{port_id: int, number: int, odp_asset_id: int, odp_code: string, odp_name: string, location_path: string|null}|null
     */
    public static function dropPortFor(ServiceSubscription $subscription): ?array
    {
        $row = DB::table('odn_ports as p')
            ->join('network_assets as a', 'a.id', '=', 'p.network_asset_id')
            ->leftJoin('locations as l', 'l.id', '=', 'a.location_id')
            ->where('p.company_id', (int) $subscription->company_id)
            ->where('p.subscription_id', $subscription->id)
            ->first(['p.id as port_id', 'p.number', 'a.id as odp_asset_id', 'a.code as odp_code', 'a.name as odp_name', 'l.path as location_path']);

        return $row === null ? null : [
            'port_id' => (int) $row->port_id,
            'number' => (int) $row->number,
            'odp_asset_id' => (int) $row->odp_asset_id,
            'odp_code' => (string) $row->odp_code,
            'odp_name' => (string) $row->odp_name,
            'location_path' => $row->location_path === null ? null : (string) $row->location_path,
        ];
    }

    private static function clearDrop(OdnPort $port, string $reason): void
    {
        $subscriptionId = $port->subscription_id;

        $port->update(['subscription_id' => null]);

        AuditService::log('odn', 'drop_released', [
            'subscription_id' => $subscriptionId,
            'port_id' => $port->id,
            'odp_asset_id' => $port->network_asset_id,
            'reason' => $reason,
        ], $port);
    }

    /** The ODP must sit under the subscription's serving POP: its location path starts with the POP's path and " > ". */
    private static function assertUnderServingPop(ServiceSubscription $subscription, NetworkAsset $odp, string $portField): void
    {
        if ($subscription->serving_pop_id === null) {
            return;
        }

        $popPath = Location::query()->whereKey($subscription->serving_pop_id)->value('path');
        $odpPath = $odp->location_id === null ? null : Location::query()->whereKey($odp->location_id)->value('path');

        if (! is_string($popPath) || ! is_string($odpPath) || ! str_starts_with($odpPath, $popPath.' > ')) {
            throw ValidationException::withMessages([$portField => "This ODP is not under the serving POP of subscription {$subscription->code}."]);
        }
    }

    /**
     * Walks up from the new input. Reaching $splitter means the input is part of its own downstream (a cycle);
     * more than MAX_HOPS splitters above it is refused, so every cycle stays within the walk. $splitter is null
     * for a splitter that is being created: it can only hit the depth bound.
     * Splitters are read with a shared lock: a current read that sees every committed rewire, never the snapshot.
     */
    private static function assertNotDownstream(?OdnSplitter $splitter, OdnPort $input): void
    {
        $port = $input;

        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            if ($port->odn_splitter_id === null) {
                return;
            }

            if ($splitter !== null && $port->odn_splitter_id === $splitter->id) {
                throw ValidationException::withMessages(['input_port_id' => 'A splitter cannot be connected to its own output or to anything downstream of it.']);
            }

            $parent = OdnSplitter::query()->sharedLock()->find($port->odn_splitter_id);
            $upstream = $parent?->input_port_id === null ? null : OdnPort::query()->find($parent->input_port_id);

            if ($upstream === null) {
                return;
            }

            $port = $upstream;
        }

        throw ValidationException::withMessages(['input_port_id' => 'The fibre chain above this port is deeper than '.self::MAX_HOPS.' splitters.']);
    }

    private static function lockFreePort(int $portId, string $field): OdnPort
    {
        $port = OdnPort::query()->lockForUpdate()->find($portId);

        if ($port === null) {
            throw ValidationException::withMessages([$field => 'The selected port does not exist.']);
        }

        if (self::isOccupied($port)) {
            throw ValidationException::withMessages([$field => 'This port is already in use.']);
        }

        return $port;
    }

    /** Held by a drop, or the input of a splitter. The splitter lookup is a current read: a connection made a moment ago must show. */
    private static function isOccupied(OdnPort $port): bool
    {
        return $port->subscription_id !== null
            || OdnSplitter::query()->where('input_port_id', $port->id)->lockForUpdate()->exists();
    }

    private static function lockAsset(int $assetId): NetworkAsset
    {
        return NetworkAsset::query()->lockForUpdate()->findOrFail($assetId);
    }

    /** @return array<string, mixed> */
    private static function portRow(NetworkAsset $host, string $kind, int $number, ?int $splitterId): array
    {
        return [
            'company_id' => $host->company_id,
            'network_asset_id' => $host->id,
            'odn_splitter_id' => $splitterId,
            'kind' => $kind,
            'number' => $number,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private static function idOf(Model|int $subject): int
    {
        return $subject instanceof Model ? (int) $subject->getKey() : $subject;
    }

    private static function companyId(): int
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Company context is required.');

        return $companyId;
    }
}
