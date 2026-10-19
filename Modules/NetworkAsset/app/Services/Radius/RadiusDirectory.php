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
 * and MySQL behave the same. Every data method refuses with kind `disabled` before any query while that company has
 * no enabled row, or while the host of its row is refused by the host policy (connection()).
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
        return $this->open($query)
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

    /** Open and not stale: the 15-minute rule exists here once, for the session lists and the online check. */
    private function open(Builder $query): Builder
    {
        return $query
            ->whereNull('acctstoptime')
            ->whereRaw('COALESCE(acctupdatetime, acctstarttime) >= ?', [now()->subMinutes(self::STALE_AFTER_MINUTES)->format('Y-m-d H:i:s')]);
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
