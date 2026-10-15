<?php

namespace Modules\NetworkAsset\Services\Radius;

use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The only code that touches the `radius` connection: the stock FreeRADIUS 3.2 tables (`radcheck`,
 * `radreply`, `radusergroup`, `radgroupreply`, `nas`; `radacct` read-only). Primitives in, so it does not
 * know Inbils models. Query builder only, so SQLite (tests) and MySQL behave the same. Every method refuses
 * with kind `disabled` before any query while `services.radius.enabled` is off.
 *
 * Limit: `nasname` is IPv4 only because `radacct.nasipaddress` is varchar(15).
 */
class RadiusDirectory
{
    /** A session with no accounting update for this long is treated as a leftover of a NAS reboot. */
    private const STALE_AFTER_MINUTES = 15;

    private const MAX_SESSIONS = 100;

    /** One transaction: the user's check row (Cleartext-Password :=) and group (priority 1) replace every earlier row of that username. */
    public function putUser(string $username, string $secret, string $group): void
    {
        if ($username === '' || mb_strlen($username) > 64 || $secret === '' || mb_strlen($secret) > 253 || $group === '' || mb_strlen($group) > 64) {
            throw RadiusException::rejected('The RADIUS user does not fit the RADIUS schema.');
        }

        $this->run(function (Connection $db) use ($username, $secret, $group): void {
            $db->transaction(function () use ($db, $username, $secret, $group): void {
                $this->deleteUserRows($db, $username);
                $db->table('radcheck')->insert(['username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $secret]);
                $db->table('radusergroup')->insert(['username' => $username, 'groupname' => $group, 'priority' => 1]);
            });
        });
    }

    public function removeUser(string $username): void
    {
        $this->run(fn (Connection $db) => $db->transaction(fn () => $this->deleteUserRows($db, $username)));
    }

    /** Drift check: the user has exactly this group and a Cleartext-Password whose HMAC (as Inbils stores it) is `$secretHash`. */
    public function hasUser(string $username, string $group, string $secretHash): bool
    {
        return $this->run(function (Connection $db) use ($username, $group, $secretHash): bool {
            $secret = $db->table('radcheck')->where('username', $username)->where('attribute', 'Cleartext-Password')->value('value');
            $groups = $db->table('radusergroup')->where('username', $username)->pluck('groupname')->all();

            return $secret !== null
                && $groups === [$group]
                && hash_equals($secretHash, hash_hmac('sha256', (string) $secret, (string) config('app.key')));
        });
    }

    /**
     * Replace the reply rows of one group (op :=).
     *
     * @param  array<string, string|int>  $replies  attribute => value
     */
    public function putGroup(string $group, array $replies): void
    {
        $this->run(function (Connection $db) use ($group, $replies): void {
            $db->transaction(function () use ($db, $group, $replies): void {
                $db->table('radgroupreply')->where('groupname', $group)->delete();
                foreach ($replies as $attribute => $value) {
                    $db->table('radgroupreply')->insert(['groupname' => $group, 'attribute' => $attribute, 'op' => ':=', 'value' => (string) $value]);
                }
            });
        });
    }

    /**
     * Upsert one FreeRADIUS client by `nasname`. A row that already exists is overwritten only when Inbils wrote it:
     * `$previousNasname` is the address the caller last wrote for this NAS, and must equal `$nasname`; any other
     * existing row belongs to operations and is refused.
     */
    public function putNas(string $nasname, string $shortname, string $secret, string $description, ?string $previousNasname): void
    {
        if (! filter_var($nasname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw RadiusException::rejected('The NAS address must be an IPv4 address.');
        }
        if ($secret === '' || mb_strlen($secret) > 60 || mb_strlen($shortname) > 32) {
            throw RadiusException::rejected('The NAS does not fit the RADIUS schema.');
        }

        $this->run(function (Connection $db) use ($nasname, $shortname, $secret, $description, $previousNasname): void {
            $db->transaction(function () use ($db, $nasname, $shortname, $secret, $description, $previousNasname): void {
                if ($previousNasname !== $nasname && $db->table('nas')->where('nasname', $nasname)->exists()) {
                    throw RadiusException::rejected('A RADIUS client with this address already exists and was not written by Inbils.');
                }

                $db->table('nas')->updateOrInsert(
                    ['nasname' => $nasname],
                    ['shortname' => $shortname, 'type' => 'other', 'secret' => $secret, 'description' => mb_substr($description, 0, 200)],
                );
            });
        });
    }

    public function removeNas(string $nasname): void
    {
        $this->run(fn (Connection $db) => $db->transaction(fn () => $db->table('nas')->where('nasname', $nasname)->delete()));
    }

    /**
     * Open, not stale sessions of one user, newest first.
     *
     * @return list<array{session_id: string, nas_ip: string, framed_ip: ?string, started_at: string, updated_at: ?string}>
     */
    public function openSessions(string $username): array
    {
        return $this->run(fn (Connection $db): array => $this->sessions($db->table('radacct')->where('username', $username), self::MAX_SESSIONS, false));
    }

    /**
     * Open, not stale sessions on one NAS address, newest first.
     *
     * @return list<array{session_id: string, nas_ip: string, framed_ip: ?string, started_at: string, updated_at: ?string, username: string}>
     */
    public function sessionsOnNas(string $nasIp, int $limit = self::MAX_SESSIONS): array
    {
        return $this->run(fn (Connection $db): array => $this->sessions($db->table('radacct')->where('nasipaddress', $nasIp), max(1, min($limit, self::MAX_SESSIONS)), true));
    }

    /**
     * Octets of the sessions started on or after `$from` (the stock 3.2 queries already fold the gigawords in).
     *
     * @return array{input_octets: int, output_octets: int}
     */
    public function usageSince(string $username, DateTimeInterface $from): array
    {
        return $this->run(function (Connection $db) use ($username, $from): array {
            $sum = $db->table('radacct')
                ->where('username', $username)
                ->where('acctstarttime', '>=', $from->format('Y-m-d H:i:s'))
                ->selectRaw('SUM(acctinputoctets) AS input_octets, SUM(acctoutputoctets) AS output_octets')
                ->first();

            return ['input_octets' => (int) ($sum->input_octets ?? 0), 'output_octets' => (int) ($sum->output_octets ?? 0)];
        });
    }

    /** When the newest finished session of this user ended (radacct's own datetime text); null while none has ended. */
    public function lastSeen(string $username): ?string
    {
        return $this->run(function (Connection $db) use ($username): ?string {
            $stoppedAt = $db->table('radacct')->where('username', $username)->max('acctstoptime');

            return $stoppedAt === null ? null : (string) $stoppedAt;
        });
    }

    /** @return list<array<string, mixed>> */
    private function sessions(Builder $query, int $limit, bool $withUsername): array
    {
        return $query
            ->whereNull('acctstoptime')
            ->whereRaw('COALESCE(acctupdatetime, acctstarttime) >= ?', [now()->subMinutes(self::STALE_AFTER_MINUTES)->format('Y-m-d H:i:s')])
            ->orderByDesc('acctstarttime')
            ->orderByDesc('radacctid')
            ->limit($limit)
            ->get(['acctsessionid', 'nasipaddress', 'framedipaddress', 'acctstarttime', 'acctupdatetime', 'username'])
            ->map(fn (object $row): array => [
                'session_id' => (string) $row->acctsessionid,
                'nas_ip' => (string) $row->nasipaddress,
                'framed_ip' => $row->framedipaddress === '' ? null : $row->framedipaddress,
                'started_at' => (string) $row->acctstarttime,
                'updated_at' => $row->acctupdatetime === null ? null : (string) $row->acctupdatetime,
            ] + ($withUsername ? ['username' => (string) $row->username] : []))
            ->all();
    }

    private function deleteUserRows(Connection $db, string $username): void
    {
        foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
            $db->table($table)->where('username', $username)->delete();
        }
    }

    /**
     * Run one unit of work on the `radius` connection. The driver exception is dropped, not chained: its message
     * carries the host, user and bindings (subscriber and NAS secrets), and a failed job stores the whole chain.
     *
     * @template T
     *
     * @param  callable(Connection): T  $work
     * @return T
     */
    private function run(callable $work): mixed
    {
        if (! config('services.radius.enabled')) {
            throw RadiusException::disabled();
        }

        try {
            return $work(DB::connection('radius'));
        } catch (RadiusException $e) {
            throw $e;
        } catch (Throwable) {
            throw RadiusException::unavailable();
        }
    }
}
