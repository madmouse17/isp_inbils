<?php

namespace Modules\NetworkAsset\Services\Radius;

use App\Models\Core\CompanyIntegration;
use App\Services\Core\IntegrationConfig;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use PDOException;
use Throwable;

/**
 * The only code that touches a RADIUS database: the stock FreeRADIUS 3.2 tables (`radcheck`, `radreply`,
 * `radusergroup`, `radgroupreply`, `nas`; `radacct` read-only). Every company has its own database, named by its
 * `radius` integration row (Pengaturan → Integrasi) and reached through the connection `radius_{companyId}`; the
 * company the request, job or command acts in decides which one. The data methods take primitives, so they do not
 * know Inbils models: only connection() and check() know the integration row. Query builder only, so SQLite (tests)
 * and MySQL behave the same, with one exception: the age of a datetime is raw SQL on the RADIUS server's own clock
 * (ageOf()). Every data
 * method refuses with kind `disabled` before any query while that company has no enabled row, or while the host of its
 * row is refused by the host policy (connection()).
 *
 * Time: FreeRADIUS writes every `radacct` datetime in the zone of its database server, which is not the application's
 * (the app runs in UTC, an ISP's server in its own zone). Ages are therefore measured inside the RADIUS statement, on
 * the server's clock, and what this class returns are moments on the application's clock (ISO 8601 with offset),
 * worked out as "now minus that age".
 *
 * Limit: `nasname` is IPv4 only because `radacct.nasipaddress` is varchar(15).
 */
class RadiusDirectory
{
    /** A session with no accounting update for this long is treated as a leftover of a NAS reboot. */
    private const STALE_AFTER_MINUTES = 15;

    private const MAX_SESSIONS = 100;

    /** The tables FreeRADIUS and Inbils need; "Uji koneksi" names the ones a database lacks. */
    private const TABLES = ['radcheck', 'radreply', 'radusergroup', 'radgroupreply', 'nas', 'radacct'];

    /** Seconds the driver waits to connect (MySQL) or for a lock (SQLite). */
    private const CONNECT_TIMEOUT = 5;

    public static function connectionName(int $companyId): string
    {
        return "radius_{$companyId}";
    }

    /**
     * The company's connection to its own RADIUS database: the one already open, else a new one built from the row on
     * top of the `radius` template of config/database.php (driver, charset, options). It is registered with its
     * configuration (DB::build), so a dropped link reconnects to the same target. Saving the row purges it
     * (IntegrationConfig::forget) and so does the worker before each job (NetworkAssetServiceProvider).
     *
     * A new link is only built for a host the policy allows, resolved now (CompanyIntegration::hostRefusal): the one
     * that is refused behaves as a RADIUS that is not configured, and the row says why.
     */
    public static function connection(CompanyIntegration $row): Connection
    {
        $name = self::connectionName((int) $row->company_id);

        if (array_key_exists($name, DB::getConnections())) {
            return DB::connection($name);
        }

        if (! $row->allowsConnection()) {
            throw RadiusException::disabled();
        }

        return self::buildConnection($row);
    }

    /** A new link from the row, without the host policy: "Uji koneksi" opens it after TestCompanyIntegration applied the policy. */
    private static function buildConnection(CompanyIntegration $row): Connection
    {
        $name = self::connectionName((int) $row->company_id);
        $template = config('database.connections.radius');

        DB::build([
            ...$template,
            'host' => (string) $row->setting('host'),
            'port' => $row->setting('port', 3306),
            'database' => (string) $row->setting('database'),
            'username' => (string) $row->setting('username'),
            'password' => (string) $row->secret('password'),
            'name' => $name,
            // `+` keeps the integer keys of the PDO options; array_merge would renumber them.
            'options' => [PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT] + ($template['options'] ?? []),
        ]);

        return DB::connection($name);
    }

    /**
     * "Uji koneksi": can the row reach its database, and does that database hold the FreeRADIUS tables? The answer is a
     * message for the admin, never the driver's text (it carries the host, the user and the bindings); null means it
     * works. A row that is still switched off can be tested. The link is built from this row and closed afterwards, so
     * neither an older link nor this one outlives the check. The host policy is the caller's to apply first
     * (TestCompanyIntegration): this method dials the host the row names.
     */
    public function check(CompanyIntegration $integration): ?string
    {
        if (blank($integration->setting('host')) || blank($integration->setting('database')) || blank($integration->setting('username'))) {
            return 'Isi host, nama database, dan nama pengguna RADIUS dulu.';
        }

        $name = self::connectionName((int) $integration->company_id);
        DB::purge($name);

        try {
            $db = self::buildConnection($integration);
            $db->select('select 1');
            $schema = $db->getSchemaBuilder();
            $missing = array_values(array_filter(self::TABLES, fn (string $table): bool => ! $schema->hasTable($table)));
        } catch (Throwable $e) {
            return self::failureMessage($e, $integration);
        } finally {
            DB::purge($name);
        }

        return $missing === [] ? null : 'Tabel FreeRADIUS tidak lengkap: '.implode(', ', $missing).'.';
    }

    /**
     * What a failed connection or query means to the admin. It reads the driver's error number and never repeats the
     * driver's text. MySQL answers 1044 (not 1049) for a database that does not exist when the user has no privileges
     * on every database, which is the usual grant of a RADIUS user.
     */
    public static function failureMessage(Throwable $e, CompanyIntegration $row): string
    {
        $database = (string) $row->setting('database');

        return match (self::driverCode($e)) {
            1045 => 'Login database RADIUS ditolak. Periksa nama pengguna dan kata sandi.',
            1049 => "Database {$database} tidak ada di server RADIUS.",
            1044 => "Pengguna database RADIUS tidak punya akses ke database {$database}, atau database itu tidak ada.",
            default => 'Database RADIUS tidak dapat dihubungi dalam 5 detik ('.$row->setting('host').':'.$row->setting('port', 3306).').',
        };
    }

    /**
     * One transaction: the user's check row (Cleartext-Password :=), group (priority 1) and, for a static address, its
     * Framed-IP-Address reply (op :=) replace every earlier row of that username.
     */
    public function putUser(string $username, string $secret, string $group, ?string $framedIp = null): void
    {
        if ($username === '' || mb_strlen($username) > 64 || $secret === '' || mb_strlen($secret) > 253 || $group === '' || mb_strlen($group) > 64) {
            throw RadiusException::rejected('The RADIUS user does not fit the RADIUS schema.');
        }
        if ($framedIp !== null && ! filter_var($framedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw RadiusException::rejected('The static address must be an IPv4 address.');
        }

        $this->run(function (Connection $db) use ($username, $secret, $group, $framedIp): void {
            $db->transaction(function () use ($db, $username, $secret, $group, $framedIp): void {
                $this->deleteUserRows($db, $username);
                $db->table('radcheck')->insert(['username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $secret]);
                $db->table('radusergroup')->insert(['username' => $username, 'groupname' => $group, 'priority' => 1]);

                if ($framedIp !== null) {
                    $db->table('radreply')->insert(['username' => $username, 'attribute' => 'Framed-IP-Address', 'op' => ':=', 'value' => $framedIp]);
                }
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

    /** The static address RADIUS hands this user (lowest row id when several exist); null while the user takes one from the NAS pool. */
    public function framedIpOf(string $username): ?string
    {
        return $this->run(fn (Connection $db): ?string => $db->table('radreply')
            ->where('username', $username)
            ->where('attribute', 'Framed-IP-Address')
            ->orderBy('id')
            ->value('value'));
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
     * Open sessions of one user, newest first, with the ones nothing has updated for 15 minutes left out. `$withStale`
     * lists those too, after the live ones, flagged `stale`: a router that reboots never sends the Stop of its sessions.
     *
     * @return list<array{session_id: string, nas_ip: string, framed_ip: ?string, started_at: string, last_seen_at: string, stale: bool}>
     */
    public function openSessions(string $username, bool $withStale = false): array
    {
        return $this->run(fn (Connection $db): array => $this->sessions($db->table('radacct')->where('username', $username), self::MAX_SESSIONS, false, $withStale));
    }

    /**
     * Open, not stale sessions on one NAS address, newest first.
     *
     * @return list<array{session_id: string, nas_ip: string, framed_ip: ?string, started_at: string, last_seen_at: string, stale: bool, username: string}>
     */
    public function sessionsOnNas(string $nasIp, int $limit = self::MAX_SESSIONS): array
    {
        return $this->run(fn (Connection $db): array => $this->sessions($db->table('radacct')->where('nasipaddress', $nasIp), max(1, min($limit, self::MAX_SESSIONS)), true));
    }

    /**
     * Which of these users have an open, not stale session: one query for the whole list. Usernames come back as
     * RADIUS stores them, whose case may differ from the caller's (MySQL compares them without case).
     *
     * @param  list<string>  $usernames
     * @return list<string>
     */
    public function onlineAmong(array $usernames): array
    {
        return $this->run(fn (Connection $db): array => $this->open($db->table('radacct')->whereIn('username', $usernames))
            ->distinct()
            ->pluck('username')
            ->map(fn (mixed $username): string => (string) $username)
            ->all());
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

    /** When the newest finished session of this user ended, as a moment on the application's clock (ISO 8601); null while none has ended. */
    public function lastSeen(string $username): ?string
    {
        return $this->run(function (Connection $db) use ($username): ?string {
            $row = $db->table('radacct')->where('username', $username)->selectRaw($this->ageOf('MAX(acctstoptime)').' AS age')->first();

            return ($row->age ?? null) === null ? null : $this->moment((int) $row->age);
        });
    }

    /**
     * The newest accounting packet this NAS sent (an update or a stop) as a moment on the application's clock (ISO
     * 8601); null while it never sent one. FreeRADIUS only answers a router whose client row it loaded at its start, so
     * a packet newer than the row was written says the restart happened.
     *
     * shortcut: reads every `radacct` row of the NAS (the stock schema indexes `nasipaddress` alone); add an index on
     * (nasipaddress, acctupdatetime) on the RADIUS side when one router holds millions of rows.
     */
    public function lastAccountingAt(string $nasIp): ?string
    {
        return $this->run(function (Connection $db) use ($nasIp): ?string {
            $row = $db->table('radacct')
                ->where('nasipaddress', $nasIp)
                ->selectRaw('MIN('.$this->ageOf('acctupdatetime').') AS updated_age, MIN('.$this->ageOf('acctstoptime').') AS stopped_age')
                ->first();
            $age = collect([$row->updated_age ?? null, $row->stopped_age ?? null])
                ->filter(fn (mixed $age): bool => $age !== null)
                ->map(fn (mixed $age): int => (int) $age)
                ->min();

            return $age === null ? null : $this->moment($age);
        });
    }

    /**
     * Open sessions of the query, newest first. Times are moments on the application's clock; `stale` says the last
     * packet is older than the 15-minute rule, which only a caller that asked for stale sessions ever sees.
     *
     * @return list<array<string, mixed>>
     */
    private function sessions(Builder $query, int $limit, bool $withUsername, bool $withStale = false): array
    {
        $sessions = $this->open($query, $withStale)
            ->select(['acctsessionid', 'nasipaddress', 'framedipaddress', 'username'])
            // A session that never sent an update has said nothing since its start.
            ->selectRaw($this->ageOf('acctstarttime').' AS started_age, '.$this->ageOf('COALESCE(acctupdatetime, acctstarttime)').' AS seen_age')
            ->orderByDesc('acctstarttime')
            ->orderByDesc('radacctid')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'session_id' => (string) $row->acctsessionid,
                'nas_ip' => (string) $row->nasipaddress,
                'framed_ip' => $row->framedipaddress === '' ? null : $row->framedipaddress,
                'started_at' => $this->moment((int) $row->started_age),
                'last_seen_at' => $this->moment((int) $row->seen_age),
                'stale' => (int) $row->seen_age > self::STALE_AFTER_MINUTES * 60,
            ] + ($withUsername ? ['username' => (string) $row->username] : []))
            ->all();

        if ($withStale) {
            // The live session stands for the account, even when a leftover row of a dead one started later (sort is stable).
            usort($sessions, fn (array $a, array $b): int => $a['stale'] <=> $b['stale']);
        }

        return $sessions;
    }

    /** Open and, unless asked otherwise, not stale: the 15-minute rule exists here once, measured on the RADIUS clock. */
    private function open(Builder $query, bool $withStale = false): Builder
    {
        $query->whereNull('acctstoptime');

        return $withStale
            ? $query
            : $query->whereRaw($this->ageOf('COALESCE(acctupdatetime, acctstarttime)').' <= ?', [self::STALE_AFTER_MINUTES * 60]);
    }

    /**
     * SQL for the seconds from a RADIUS datetime to the RADIUS server's own clock. Its datetimes are in its zone, not the
     * application's, so every age is measured there and only the duration comes back (F7-D20). The one raw expression of
     * this class; the SQLite stand-in of the tests defines NOW() and UNIX_TIMESTAMP() too.
     */
    private function ageOf(string $expression): string
    {
        return "(UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP({$expression}))";
    }

    /** The moment on the application's clock that was this many seconds ago; a packet stamped ahead of the server reads as now. */
    private function moment(int $secondsAgo): string
    {
        return now()->subSeconds(max(0, $secondsAgo))->toIso8601String();
    }

    private function deleteUserRows(Connection $db, string $username): void
    {
        foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
            $db->table($table)->where('username', $username)->delete();
        }
    }

    /** The driver's own error number, from the PDO exception somewhere in the chain; null for anything else. */
    private static function driverCode(Throwable $e): ?int
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if (! $cause instanceof PDOException) {
                continue;
            }

            if (is_int($cause->errorInfo[1] ?? null)) {
                return $cause->errorInfo[1];
            }

            // A failed connect has no errorInfo: the number is only in the message, "SQLSTATE[HY000] [1045] Access denied ...".
            if (preg_match('/^SQLSTATE\[[^\]]+\] \[(\d+)\]/', $cause->getMessage(), $found) === 1) {
                return (int) $found[1];
            }
        }

        return null;
    }

    /**
     * Run one unit of work on the connection of the company in scope. The driver exception is dropped, not chained: its
     * message carries the host, user and bindings (subscriber and NAS secrets), and a failed job stores the whole chain.
     *
     * @template T
     *
     * @param  callable(Connection): T  $work
     * @return T
     */
    private function run(callable $work): mixed
    {
        $row = app(IntegrationConfig::class)->current('radius');

        if ($row?->is_enabled !== true) {
            throw RadiusException::disabled();
        }

        try {
            return $work(self::connection($row));
        } catch (RadiusException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Class only: the message names the host.
            Log::warning('radius.unavailable', ['error' => $e::class]);
            throw RadiusException::unavailable();
        }
    }
}
