<?php

namespace Modules\Ipam\Services;

use App\Models\Core\ServiceSubscription;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Support\RecordNotFound;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Ipam\Events\IpAssignmentChanged;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Support\IpNotation;
use Modules\Ipam\Support\ParsedAddress;
use Modules\NetworkAsset\Models\NetworkAsset;

/**
 * The single place that hands out and frees addresses. Every insert leans on the unique indexes of
 * ip_addresses; the checks before it only exist to give a clear message.
 */
class IpAssignmentService
{
    /** Lowest-free scanning stays bounded: an IPv4 pool wider than this must be given a typed address. */
    private const AUTO_MIN_PREFIX_LENGTH = 16;

    /** The suggestion shown on every subscription page scans the whole pool, so it is limited to pools of up to 1024 addresses. */
    private const PEEK_MIN_PREFIX_LENGTH = 22;

    private const AUTO_ATTEMPTS = 3;

    /** @var list<string> asset columns mirrored into the register (Q6a: the columns stay a display copy) */
    private const ASSET_FIELDS = ['ip_address', 'management_ip'];

    /**
     * Gives the subscription a static address from a customer_static pool: the typed one, or for IPv4 the
     * lowest free usable address. One address per family per subscription.
     */
    public static function assignToSubscription(ServiceSubscription $subscription, IpPrefix $pool, ?string $address = null): IpAddress
    {
        self::companyId();

        return DB::transaction(function () use ($subscription, $pool, $address): IpAddress {
            $locked = ServiceSubscription::query()->lockForUpdate()->findOrFail($subscription->id);

            if ($locked->status === 'terminated') {
                throw ValidationException::withMessages(['subscription' => "Langganan {$locked->code} sudah berhenti dan tidak dapat memakai alamat."]);
            }

            // The pool row is the mutex of automatic allocation: concurrent assignments queue here.
            $pool = IpPrefix::query()->lockForUpdate()->findOrFail($pool->id);

            if ($pool->usage !== 'customer_static') {
                throw ValidationException::withMessages(['ip_prefix_id' => "{$pool->cidr} bukan pool statis pelanggan."]);
            }

            // The subscription row is locked, so its addresses cannot change meanwhile: a plain read is enough
            // and avoids a gap lock on the unique index.
            $held = IpAddress::query()
                ->where('subscription_id', $locked->id)
                ->where('family', $pool->family)
                ->first();

            if ($held !== null) {
                throw ValidationException::withMessages(['ip_prefix_id' => "Langganan {$locked->code} sudah memakai alamat IPv{$pool->family} {$held->address}. Lepas dulu."]);
            }

            $row = $address === null
                ? self::allocateLowestFree($pool, $locked)
                : self::claimTyped($pool, $locked, $address);

            AuditService::log('ipam', 'address_assigned', [
                'subscription_id' => $locked->id,
                'address' => $row->address,
                'ip_prefix_id' => $row->ip_prefix_id,
                'mode' => $address === null ? 'lowest_free' : 'typed',
            ], $row);

            IpAssignmentChanged::dispatch((int) $locked->company_id, $locked->id);

            return $row;
        }, 3);
    }

    /** Holds an address back from customers (gateway, loopback...). Allowed in any prefix. */
    public static function reserve(IpPrefix $prefix, string $address, ?string $note = null): IpAddress
    {
        self::companyId();

        return DB::transaction(function () use ($prefix, $address, $note): IpAddress {
            $prefix = IpPrefix::query()->lockForUpdate()->findOrFail($prefix->id);
            $parsed = self::parseInside($prefix, $address);

            self::assertFree($parsed);

            $row = self::insertRow($parsed, self::containingPrefix($parsed)?->id, IpAddress::STATUS_RESERVED, [], $note);

            AuditService::log('ipam', 'address_reserved', ['address' => $row->address, 'note' => $note], $row);

            return $row;
        }, 3);
    }

    /** Frees a subscription-held or reserved address. An asset's address is changed on the asset. */
    public static function release(IpAddress $address): void
    {
        DB::transaction(function () use ($address): void {
            $row = IpAddress::query()->lockForUpdate()->findOrFail($address->id);

            if ($row->network_asset_id !== null) {
                throw ValidationException::withMessages(['address' => "{$row->address} milik sebuah aset jaringan. Ubah lewat aset tersebut."]);
            }

            self::deleteRow($row, 'released');
        });
    }

    /** @return int number of addresses released */
    public static function releaseForSubscription(ServiceSubscription $subscription, string $reason = 'subscription terminated'): int
    {
        return DB::transaction(function () use ($subscription, $reason): int {
            // The subscription row lock keeps assignments out; the rows are then deleted by key.
            ServiceSubscription::query()->withoutCompany()->whereKey($subscription->id)->lockForUpdate()->value('id');

            $rows = IpAddress::query()
                ->forCompany((int) $subscription->company_id)
                ->where('subscription_id', $subscription->id)
                ->get();

            foreach ($rows as $row) {
                self::deleteRow($row, $reason);
            }

            return $rows->count();
        });
    }

    /**
     * Write-through of the asset's ip_address and management_ip (Q6a). Rows for values that went away are
     * freed, new values are claimed; a value another holder owns is a 422 on that field. Call it inside the
     * transaction that saves the asset so a refusal writes nothing.
     */
    public static function syncAssetAddresses(NetworkAsset $asset): void
    {
        $companyId = self::companyId();
        RecordNotFound::abortUnless((int) $asset->company_id === $companyId);

        DB::transaction(function () use ($asset): void {
            // The asset row is the mutex of its own addresses; its rows are then read without a range lock.
            NetworkAsset::query()->whereKey($asset->id)->lockForUpdate()->value('id');

            $wanted = [];

            foreach (self::ASSET_FIELDS as $field) {
                $value = trim((string) $asset->getAttribute($field));

                if ($value === '') {
                    continue;
                }

                $parsed = IpNotation::address($value) ?? throw ValidationException::withMessages([$field => 'Masukkan alamat IP yang valid.']);
                $wanted[$parsed->comparable] ??= ['field' => $field, 'parsed' => $parsed];
            }

            $current = IpAddress::query()->where('network_asset_id', $asset->id)->get()->keyBy('address_cmp');

            foreach ($current as $comparable => $row) {
                if (! isset($wanted[$comparable])) {
                    self::deleteRow($row, 'asset address changed');
                }
            }

            foreach ($wanted as $comparable => $entry) {
                if (! $current->has($comparable)) {
                    self::claimForAsset($asset, $entry['parsed'], $entry['field']);
                }
            }
        });
    }

    public static function releaseForAsset(NetworkAsset $asset): int
    {
        return DB::transaction(function () use ($asset): int {
            NetworkAsset::query()->withoutCompany()->whereKey($asset->id)->lockForUpdate()->value('id');

            $rows = IpAddress::query()
                ->forCompany((int) $asset->company_id)
                ->where('network_asset_id', $asset->id)
                ->get();

            foreach ($rows as $row) {
                self::deleteRow($row, 'asset deleted');
            }

            return $rows->count();
        });
    }

    /**
     * Inserts the asset's row for an address nobody holds. Throws a duplicate-key exception when another
     * holder wins a race; callers that cannot tolerate it check findHolder() first.
     */
    public static function registerAssetAddress(NetworkAsset $asset, ParsedAddress $parsed): IpAddress
    {
        return self::insertRow($parsed, self::containingPrefix($parsed)?->id, IpAddress::STATUS_ASSIGNED, ['network_asset_id' => $asset->id]);
    }

    /** Plain read by default (a free address would take a gap lock); $current reads the committed row and locks it. */
    public static function findHolder(ParsedAddress $parsed, bool $current = false): ?IpAddress
    {
        return IpAddress::query()
            ->where('address_cmp', $parsed->comparable)
            ->when($current, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /** "held by subscription SUB-1", "held by asset AST-2" or "reserved (gateway)". */
    public static function describeHolder(IpAddress $row): string
    {
        if ($row->subscription_id !== null) {
            return 'held by subscription '.ServiceSubscription::query()->withTrashed()->whereKey($row->subscription_id)->value('code');
        }

        if ($row->network_asset_id !== null) {
            return 'held by asset '.NetworkAsset::query()->withTrashed()->whereKey($row->network_asset_id)->value('code');
        }

        return 'reserved'.($row->note !== null && $row->note !== '' ? " ({$row->note})" : '');
    }

    /** The same holder in Indonesian, for the message a person reads: "dipakai langganan SUB-1", "dipakai aset AST-2" or "dicadangkan (gateway)". */
    private static function holderPhrase(IpAddress $row): string
    {
        if ($row->subscription_id !== null) {
            return 'dipakai langganan '.ServiceSubscription::query()->withTrashed()->whereKey($row->subscription_id)->value('code');
        }

        if ($row->network_asset_id !== null) {
            return 'dipakai aset '.NetworkAsset::query()->withTrashed()->whereKey($row->network_asset_id)->value('code');
        }

        return 'dicadangkan'.($row->note !== null && $row->note !== '' ? " ({$row->note})" : '');
    }

    /** @return Collection<int, IpAddress> the subscription's addresses, IPv4 first, with their prefix */
    public static function forSubscription(ServiceSubscription $subscription): Collection
    {
        return IpAddress::query()
            ->forCompany((int) $subscription->company_id)
            ->where('subscription_id', $subscription->id)
            ->with('prefix:id,cidr')
            ->orderBy('family')
            ->get();
    }

    /**
     * Provisioning contract: the subscription's static IPv4 in dotted form, or null when it is dynamic.
     * Needs no ambient company: the subscription carries its own.
     */
    public static function staticAddressFor(ServiceSubscription $subscription): ?string
    {
        $address = IpAddress::query()
            ->forCompany((int) $subscription->company_id)
            ->where('subscription_id', $subscription->id)
            ->where('family', 4)
            ->value('address');

        return is_string($address) ? $address : null;
    }

    private static function claimTyped(IpPrefix $pool, ServiceSubscription $subscription, string $input): IpAddress
    {
        $parsed = self::parseInside($pool, $input);
        $containing = self::containingPrefix($parsed);

        if ($containing !== null && $containing->usage !== 'customer_static') {
            throw ValidationException::withMessages(['address' => "{$parsed->address} berada di {$containing->cidr}, yang bukan pool statis pelanggan."]);
        }

        self::assertFree($parsed);

        try {
            return self::insertRow($parsed, $containing?->id, IpAddress::STATUS_ASSIGNED, ['subscription_id' => $subscription->id]);
        } catch (UniqueConstraintViolationException) {
            throw self::conflict($parsed);
        }
    }

    private static function allocateLowestFree(IpPrefix $pool, ServiceSubscription $subscription): IpAddress
    {
        if ($pool->family !== 4) {
            throw ValidationException::withMessages(['address' => 'Alokasi otomatis hanya untuk pool IPv4. Ketik alamatnya.']);
        }

        if ($pool->prefix_length < self::AUTO_MIN_PREFIX_LENGTH) {
            throw ValidationException::withMessages(['address' => "{$pool->cidr} lebih besar dari /".self::AUTO_MIN_PREFIX_LENGTH.'. Ketik alamatnya.']);
        }

        [$low, $high, $nested, $taken] = self::autoWindow($pool);

        for ($attempt = 1; $attempt <= self::AUTO_ATTEMPTS; $attempt++) {
            $candidate = self::firstFree($low, $high, $taken, $nested);

            if ($candidate === null) {
                throw ValidationException::withMessages(['ip_prefix_id' => "{$pool->cidr} tidak punya alamat kosong."]);
            }

            $parsed = IpNotation::address(IpNotation::ipv4FromInt($candidate)) ?? throw new LogicException('Allocated an unparseable IPv4 address.');

            try {
                return self::insertRow($parsed, $pool->id, IpAddress::STATUS_ASSIGNED, ['subscription_id' => $subscription->id]);
            } catch (UniqueConstraintViolationException) {
                // A writer outside the pool lock (an asset, a reservation) took it first: move on to the next one.
                $taken[$candidate] = true;
            }
        }

        throw ValidationException::withMessages(['ip_prefix_id' => "{$pool->cidr} sedang sibuk. Coba lagi."]);
    }

    /**
     * The address an automatic assignment would take now (IPv4 pools of /22 or smaller), without writing anything.
     * Null when the pool is IPv6, bigger than /22 (the scan reads every held address of the pool) or full.
     * A hint: a concurrent writer can still take it.
     */
    public static function peekLowestFree(IpPrefix $pool): ?string
    {
        if ($pool->family !== 4 || $pool->prefix_length < self::PEEK_MIN_PREFIX_LENGTH) {
            return null;
        }

        [$low, $high, $nested, $taken] = self::autoWindow($pool);
        $candidate = self::firstFree($low, $high, $taken, $nested);

        return $candidate === null ? null : IpNotation::ipv4FromInt($candidate);
    }

    /** @return array{0: int, 1: int, 2: list<array{0: int, 1: int}>, 3: array<int, true>} */
    private static function autoWindow(IpPrefix $pool): array
    {
        $low = IpNotation::ipv4ToInt($pool->range_from);
        $high = IpNotation::ipv4ToInt($pool->range_to);

        if ($pool->prefix_length <= 30) {
            $low++;
            $high--;
        }

        // Space carved out for a nested prefix belongs to that prefix, not to this pool.
        $nested = IpPrefix::query()->where('parent_id', $pool->id)->get(['range_from', 'range_to'])
            ->map(fn (IpPrefix $child): array => [IpNotation::ipv4ToInt($child->range_from), IpNotation::ipv4ToInt($child->range_to)])
            ->all();

        // One plain read of the pool; the unique index decides any race that happens after it.
        $taken = IpAddress::query()
            ->whereBetween('address_cmp', [$pool->range_from, $pool->range_to])
            ->pluck('address_cmp')
            ->mapWithKeys(fn (string $comparable): array => [IpNotation::ipv4ToInt($comparable) => true])
            ->all();

        return [$low, $high, $nested, $taken];
    }

    /**
     * @param  array<int, true>  $taken
     * @param  list<array{0: int, 1: int}>  $nested
     */
    private static function firstFree(int $low, int $high, array $taken, array $nested): ?int
    {
        for ($number = $low; $number <= $high; $number++) {
            if (! isset($taken[$number]) && ! self::insideAny($number, $nested)) {
                return $number;
            }
        }

        return null;
    }

    private static function claimForAsset(NetworkAsset $asset, ParsedAddress $parsed, string $field): void
    {
        if (self::findHolder($parsed) !== null) {
            throw self::conflict($parsed, $field);
        }

        try {
            self::registerAssetAddress($asset, $parsed);
        } catch (UniqueConstraintViolationException) {
            throw self::conflict($parsed, $field);
        }
    }

    private static function parseInside(IpPrefix $prefix, string $input): ParsedAddress
    {
        $parsed = IpNotation::address($input) ?? throw ValidationException::withMessages(['address' => 'Masukkan alamat IPv4 atau IPv6 yang valid.']);

        if ($parsed->family !== $prefix->family || ! IpNotation::between($parsed->comparable, $prefix->range_from, $prefix->range_to)) {
            throw ValidationException::withMessages(['address' => "{$parsed->address} berada di luar {$prefix->cidr}."]);
        }

        // IPv4 blocks up to /30 keep their first and last address for the network and broadcast.
        if ($prefix->family === 4 && $prefix->prefix_length <= 30
            && ($parsed->comparable === $prefix->range_from || $parsed->comparable === $prefix->range_to)) {
            throw ValidationException::withMessages(['address' => "{$parsed->address} adalah alamat jaringan atau broadcast dari {$prefix->cidr}."]);
        }

        return $parsed;
    }

    private static function assertFree(ParsedAddress $parsed): void
    {
        if (self::findHolder($parsed) !== null) {
            throw self::conflict($parsed);
        }
    }

    /** 422 naming the current holder; looked up again so a lost race still says who won. */
    private static function conflict(ParsedAddress $parsed, string $field = 'address'): ValidationException
    {
        $holder = self::findHolder($parsed, true);

        return ValidationException::withMessages([
            $field => $holder === null
                ? "{$parsed->address} baru saja diambil. Coba lagi."
                : "{$parsed->address} sudah ".self::holderPhrase($holder).'.',
        ]);
    }

    private static function containingPrefix(ParsedAddress $parsed): ?IpPrefix
    {
        return IpPrefix::query()
            ->where('family', $parsed->family)
            ->where('range_from', '<=', $parsed->comparable)
            ->where('range_to', '>=', $parsed->comparable)
            ->orderByDesc('prefix_length')
            ->first();
    }

    /** @param array{subscription_id?: int, network_asset_id?: int} $holder */
    private static function insertRow(ParsedAddress $parsed, ?int $prefixId, string $status, array $holder, ?string $note = null): IpAddress
    {
        return IpAddress::query()->create([
            ...$holder,
            'ip_prefix_id' => $prefixId,
            'family' => $parsed->family,
            'address' => $parsed->address,
            'address_cmp' => $parsed->comparable,
            'status' => $status,
            'note' => $note,
            'assigned_by' => Auth::id(),
        ]);
    }

    private static function deleteRow(IpAddress $row, string $reason): void
    {
        $row->delete();

        AuditService::log('ipam', 'address_released', [
            'address' => $row->address,
            'status' => $row->status,
            'subscription_id' => $row->subscription_id,
            'network_asset_id' => $row->network_asset_id,
            'reason' => $reason,
        ], $row);

        if ($row->subscription_id !== null) {
            IpAssignmentChanged::dispatch((int) $row->company_id, $row->subscription_id);
        }
    }

    /** @param list<array{0: int, 1: int}> $ranges */
    private static function insideAny(int $number, array $ranges): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($number >= $from && $number <= $to) {
                return true;
            }
        }

        return false;
    }

    private static function companyId(): int
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Company context is required.');

        return $companyId;
    }
}
