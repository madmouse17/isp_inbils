<?php

namespace Tests\Feature\NetworkAsset;

use Illuminate\Support\Facades\DB;
use Modules\NetworkAsset\Services\Radius\RadiusDirectory;
use Modules\NetworkAsset\Services\Radius\RadiusException;
use Tests\TestCase;
use Tests\Traits\InteractsWithRadius;

class RadiusDirectoryTest extends TestCase
{
    use InteractsWithRadius;

    private const FAKE_SECRET = 'fake-subscriber-pass-1';

    private RadiusDirectory $radius;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRadius();
        $this->radius = new RadiusDirectory();
    }

    private function kind(callable $call): string
    {
        try {
            $call();
        } catch (RadiusException $e) {
            return $e->kind;
        }

        return 'none';
    }

    public function test_put_user_writes_check_and_group_rows(): void
    {
        $this->radius->putUser('budi@acme', self::FAKE_SECRET, 'acme/home-20m');

        $this->assertSame(
            [['username' => 'budi@acme', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => self::FAKE_SECRET]],
            array_map(fn (array $r): array => array_diff_key($r, ['id' => 1]), $this->radiusRows('radcheck')),
        );
        $groups = $this->radiusRows('radusergroup');
        $this->assertCount(1, $groups);
        $this->assertSame(['budi@acme', 'acme/home-20m', 1], [$groups[0]['username'], $groups[0]['groupname'], (int) $groups[0]['priority']]);
    }

    public function test_put_user_replaces_prior_rows_of_that_username_only(): void
    {
        $db = $this->radiusConnection();
        $db->table('radcheck')->insert([
            ['username' => 'budi@acme', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'old-fake'],
            ['username' => 'budi@acme', 'attribute' => 'Simultaneous-Use', 'op' => ':=', 'value' => '1'],
            ['username' => 'ani@acme', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'ani-fake'],
        ]);
        $db->table('radreply')->insert([
            ['username' => 'budi@acme', 'attribute' => 'Framed-IP-Address', 'op' => '=', 'value' => '198.51.100.9'],
            ['username' => 'ani@acme', 'attribute' => 'Framed-IP-Address', 'op' => '=', 'value' => '198.51.100.10'],
        ]);
        $db->table('radusergroup')->insert([
            ['username' => 'budi@acme', 'groupname' => 'acme/isolir', 'priority' => 1],
            ['username' => 'ani@acme', 'groupname' => 'acme/home-20m', 'priority' => 1],
        ]);

        $this->radius->putUser('budi@acme', self::FAKE_SECRET, 'acme/home-20m');

        $this->assertSame([self::FAKE_SECRET], array_column($this->radiusRows('radcheck', ['username' => 'budi@acme']), 'value'));
        $this->assertSame([], $this->radiusRows('radreply', ['username' => 'budi@acme']));
        $this->assertSame(['acme/home-20m'], array_column($this->radiusRows('radusergroup', ['username' => 'budi@acme']), 'groupname'));
        $this->assertSame(['ani-fake'], array_column($this->radiusRows('radcheck', ['username' => 'ani@acme']), 'value'));
        $this->assertCount(1, $this->radiusRows('radreply', ['username' => 'ani@acme']));
        $this->assertSame(['acme/home-20m'], array_column($this->radiusRows('radusergroup', ['username' => 'ani@acme']), 'groupname'));
    }

    public function test_put_user_writes_a_static_address_as_the_framed_ip_reply_of_that_user(): void
    {
        $this->radius->putUser('budi@acme', self::FAKE_SECRET, 'acme/home-20m', '10.70.0.9');

        $rows = $this->radiusRows('radreply');
        $this->assertCount(1, $rows);
        $this->assertSame(['budi@acme', 'Framed-IP-Address', ':=', '10.70.0.9'], [$rows[0]['username'], $rows[0]['attribute'], $rows[0]['op'], $rows[0]['value']]);
    }

    public function test_put_user_refuses_a_static_address_that_is_not_ipv4_and_writes_nothing(): void
    {
        foreach (['2001:db8::1', '10.70.0.999', 'not-an-ip', ''] as $address) {
            $this->assertSame(RadiusException::REJECTED, $this->kind(fn () => $this->radius->putUser('budi@acme', self::FAKE_SECRET, 'g', $address)), $address);
        }

        $this->assertSame([], $this->radiusRows('radcheck'));
        $this->assertSame([], $this->radiusRows('radreply'));
    }

    public function test_framed_ip_of_reads_the_address_of_that_user_only(): void
    {
        $this->assertNull($this->radius->framedIpOf('budi@acme'));

        $this->radius->putUser('budi@acme', self::FAKE_SECRET, 'acme/home-20m', '10.70.0.9');
        $this->radius->putUser('ani@acme', 'ani-fake-pass', 'acme/home-20m');

        $this->assertSame('10.70.0.9', $this->radius->framedIpOf('budi@acme'));
        $this->assertNull($this->radius->framedIpOf('ani@acme'));
    }

    public function test_put_user_is_one_transaction(): void
    {
        $this->radiusConnection()->statement('drop table radusergroup');

        $this->assertSame(RadiusException::UNAVAILABLE, $this->kind(fn () => $this->radius->putUser('budi@acme', self::FAKE_SECRET, 'g')));
        $this->assertSame([], $this->radiusRows('radcheck'));
    }

    public function test_remove_user_leaves_other_usernames(): void
    {
        $this->radius->putUser('budi@acme', self::FAKE_SECRET, 'acme/home-20m');
        $this->radius->putUser('ani@acme', 'ani-fake-pass', 'acme/home-20m');
        $this->radiusConnection()->table('radreply')->insert(['username' => 'budi@acme', 'attribute' => 'Framed-IP-Address', 'op' => '=', 'value' => '198.51.100.9']);

        $this->radius->removeUser('budi@acme');

        foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
            $this->assertSame([], $this->radiusRows($table, ['username' => 'budi@acme']));
        }
        $this->assertCount(1, $this->radiusRows('radcheck', ['username' => 'ani@acme']));
        $this->assertCount(1, $this->radiusRows('radusergroup', ['username' => 'ani@acme']));
    }

    public function test_has_user_detects_drift(): void
    {
        $hash = hash_hmac('sha256', self::FAKE_SECRET, (string) config('app.key'));
        $this->assertFalse($this->radius->hasUser('budi@acme', 'acme/home-20m', $hash));

        $this->radius->putUser('budi@acme', self::FAKE_SECRET, 'acme/home-20m');

        $this->assertTrue($this->radius->hasUser('budi@acme', 'acme/home-20m', $hash));
        $this->assertFalse($this->radius->hasUser('budi@acme', 'acme/isolir', $hash));
        $this->assertFalse($this->radius->hasUser('budi@acme', 'acme/home-20m', hash_hmac('sha256', 'other', (string) config('app.key'))));
        $this->assertFalse($this->radius->hasUser('ani@acme', 'acme/home-20m', $hash));
    }

    public function test_put_group_replaces_only_that_groups_replies(): void
    {
        $this->radiusConnection()->table('radgroupreply')->insert([
            ['groupname' => 'acme/home-20m', 'attribute' => 'Mikrotik-Rate-Limit', 'op' => ':=', 'value' => '1M/1M'],
            ['groupname' => 'acme/home-20m', 'attribute' => 'Stale-Attribute', 'op' => ':=', 'value' => 'x'],
            ['groupname' => 'acme/isolir', 'attribute' => 'Mikrotik-Group', 'op' => ':=', 'value' => 'isolir'],
        ]);

        $this->radius->putGroup('acme/home-20m', ['Mikrotik-Group' => 'home-20m', 'Mikrotik-Rate-Limit' => '5M/20M', 'Acct-Interim-Interval' => 300]);

        $rows = $this->radiusRows('radgroupreply', ['groupname' => 'acme/home-20m']);
        $this->assertSame(
            ['Mikrotik-Group' => 'home-20m', 'Mikrotik-Rate-Limit' => '5M/20M', 'Acct-Interim-Interval' => '300'],
            array_column($rows, 'value', 'attribute'),
        );
        $this->assertSame([':='], array_unique(array_column($rows, 'op')));
        $this->assertSame(['Mikrotik-Group'], array_column($this->radiusRows('radgroupreply', ['groupname' => 'acme/isolir']), 'attribute'));
    }

    public function test_put_nas_upserts_by_nasname_and_leaves_other_rows(): void
    {
        $this->radiusConnection()->table('nas')->insert(['nasname' => '127.0.0.1', 'shortname' => 'lab', 'secret' => 'lab-fake']);

        $this->radius->putNas('192.0.2.10', 'acme', 'nas-fake-secret', 'RTR-1 Core', null);
        $this->radius->putNas('192.0.2.10', 'acme', 'nas-fake-secret-2', 'RTR-1 Core renamed', '192.0.2.10');

        $rows = $this->radiusRows('nas', ['nasname' => '192.0.2.10']);
        $this->assertCount(1, $rows);
        $this->assertSame(['acme', 'other', 'nas-fake-secret-2', 'RTR-1 Core renamed'], [$rows[0]['shortname'], $rows[0]['type'], $rows[0]['secret'], $rows[0]['description']]);
        $lab = $this->radiusRows('nas', ['nasname' => '127.0.0.1']);
        $this->assertCount(1, $lab);
        $this->assertSame(['lab', 'lab-fake'], [$lab[0]['shortname'], $lab[0]['secret']]);
    }

    public function test_put_nas_refuses_what_the_schema_cannot_hold(): void
    {
        $this->assertSame(RadiusException::REJECTED, $this->kind(fn () => $this->radius->putNas('2001:db8::1', 'acme', 'nas-fake-secret', 'x', null)));
        $this->assertSame(RadiusException::REJECTED, $this->kind(fn () => $this->radius->putNas('nas.example.test', 'acme', 'nas-fake-secret', 'x', null)));
        $this->assertSame(RadiusException::REJECTED, $this->kind(fn () => $this->radius->putNas('192.0.2.10', 'acme', str_repeat('s', 61), 'x', null)));
        $this->assertSame([], $this->radiusRows('nas'));
    }

    public function test_put_nas_refuses_to_overwrite_a_row_inbils_did_not_write(): void
    {
        $this->radiusConnection()->table('nas')->insert(['nasname' => '192.0.2.10', 'shortname' => 'ops', 'secret' => 'ops-fake', 'description' => 'managed by ops']);

        $this->assertSame(RadiusException::REJECTED, $this->kind(fn () => $this->radius->putNas('192.0.2.10', 'acme', 'nas-fake-secret', 'RTR-1', null)));
        $this->assertSame(RadiusException::REJECTED, $this->kind(fn () => $this->radius->putNas('192.0.2.10', 'acme', 'nas-fake-secret', 'RTR-1', '192.0.2.99')));

        $rows = $this->radiusRows('nas');
        $this->assertCount(1, $rows);
        $this->assertSame(['ops', 'ops-fake', 'managed by ops'], [$rows[0]['shortname'], $rows[0]['secret'], $rows[0]['description']]);
    }

    public function test_a_failing_write_leaks_no_secret_in_the_exception_or_its_chain(): void
    {
        $secret = 'nas-leak-canary-secret';
        $this->radiusConnection()->unprepared('CREATE TRIGGER fail_radcheck BEFORE INSERT ON radcheck BEGIN SELECT RAISE(ABORT, \'boom\'); END');
        $this->radiusConnection()->unprepared('CREATE TRIGGER fail_nas BEFORE INSERT ON nas BEGIN SELECT RAISE(ABORT, \'boom\'); END');

        foreach ([
            fn () => $this->radius->putUser('budi@acme', 'user-leak-canary-secret', 'acme/home-20m'),
            fn () => $this->radius->putNas('192.0.2.10', 'acme', $secret, 'RTR-1', null),
        ] as $call) {
            try {
                $call();
                $this->fail('The failing insert must throw.');
            } catch (RadiusException $e) {
                $this->assertSame(RadiusException::UNAVAILABLE, $e->kind);
                $this->assertNull($e->getPrevious());
                $this->assertStringNotContainsString('leak-canary', (string) $e);
            }
        }
    }

    public function test_remove_nas_deletes_only_that_row(): void
    {
        $this->radius->putNas('192.0.2.10', 'acme', 'nas-fake-secret', 'one', null);
        $this->radius->putNas('192.0.2.11', 'acme', 'nas-fake-secret', 'two', null);

        $this->radius->removeNas('192.0.2.10');

        $this->assertSame(['192.0.2.11'], array_column($this->radiusRows('nas'), 'nasname'));
    }

    public function test_open_sessions_ignore_stopped_and_stale_rows(): void
    {
        $fmt = fn (int $minutesAgo): string => now()->subMinutes($minutesAgo)->format('Y-m-d H:i:s');
        $this->seedRadiusSession(['acctsessionid' => 'fresh-old-start', 'acctstarttime' => $fmt(120), 'acctupdatetime' => $fmt(1)]);
        $this->seedRadiusSession(['acctsessionid' => 'newest', 'acctstarttime' => $fmt(5), 'acctupdatetime' => null]);
        $this->seedRadiusSession(['acctsessionid' => 'stopped', 'acctstoptime' => $fmt(1)]);
        $this->seedRadiusSession(['acctsessionid' => 'stale', 'acctstarttime' => $fmt(120), 'acctupdatetime' => $fmt(20)]);
        $this->seedRadiusSession(['acctsessionid' => 'stale-no-update', 'acctstarttime' => $fmt(30), 'acctupdatetime' => null]);
        $this->seedRadiusSession(['acctsessionid' => 'other-user', 'username' => 'ani@acme']);

        $sessions = $this->radius->openSessions('budi@acme');

        $this->assertSame(['newest', 'fresh-old-start'], array_column($sessions, 'session_id'));
        $this->assertSame(['session_id', 'nas_ip', 'framed_ip', 'started_at', 'updated_at'], array_keys($sessions[0]));
        $this->assertSame('192.0.2.10', $sessions[0]['nas_ip']);
        $this->assertSame('198.51.100.7', $sessions[0]['framed_ip']);
        $this->assertNull($sessions[0]['updated_at']);
    }

    public function test_sessions_on_nas_are_scoped_and_capped(): void
    {
        foreach (range(1, 5) as $i) {
            $this->seedRadiusSession(['acctsessionid' => "s$i", 'username' => "user$i@acme"]);
        }
        $this->seedRadiusSession(['acctsessionid' => 'elsewhere', 'nasipaddress' => '192.0.2.99']);
        $this->seedRadiusSession(['acctsessionid' => 'stopped', 'acctstoptime' => now()->format('Y-m-d H:i:s')]);

        $all = $this->radius->sessionsOnNas('192.0.2.10');
        $this->assertCount(5, $all);
        $this->assertSame(['session_id', 'nas_ip', 'framed_ip', 'started_at', 'updated_at', 'username'], array_keys($all[0]));
        $this->assertCount(2, $this->radius->sessionsOnNas('192.0.2.10', 2));
    }

    public function test_usage_since_sums_sessions_started_from_the_date(): void
    {
        $this->seedRadiusSession(['acctstarttime' => '2026-09-30 23:59:59', 'acctstoptime' => '2026-10-01 00:10:00', 'acctinputoctets' => 5_000_000_000, 'acctoutputoctets' => 1]);
        $this->seedRadiusSession(['acctstarttime' => '2026-10-01 00:00:00', 'acctstoptime' => '2026-10-01 01:00:00', 'acctinputoctets' => 4_000_000_000, 'acctoutputoctets' => 7_000_000_000]);
        $this->seedRadiusSession(['acctstarttime' => '2026-10-05 08:00:00', 'acctinputoctets' => 10, 'acctoutputoctets' => 20]);
        $this->seedRadiusSession(['acctstarttime' => '2026-10-05 08:00:00', 'username' => 'ani@acme', 'acctinputoctets' => 99, 'acctoutputoctets' => 99]);

        $usage = $this->radius->usageSince('budi@acme', new \DateTimeImmutable('2026-10-01 00:00:00'));

        $this->assertSame(['input_octets' => 4_000_000_010, 'output_octets' => 7_000_000_020], $usage);
        $this->assertSame(['input_octets' => 0, 'output_octets' => 0], $this->radius->usageSince('nobody@acme', new \DateTimeImmutable('2026-10-01')));
    }

    public function test_last_seen_is_the_newest_stop_time_of_that_user_only(): void
    {
        $this->seedRadiusSession(['acctstarttime' => '2026-10-13 06:00:00', 'acctstoptime' => '2026-10-13 07:30:00']);
        $this->seedRadiusSession(['acctstarttime' => '2026-10-14 06:00:00', 'acctstoptime' => '2026-10-14 06:45:10']);
        $this->seedRadiusSession(['acctstarttime' => '2026-10-12 06:00:00', 'acctstoptime' => '2026-10-12 06:10:00']);
        $this->seedRadiusSession(['username' => 'ani@acme', 'acctstoptime' => '2026-10-15 00:00:00']);
        $this->seedRadiusSession(['acctstarttime' => '2026-10-14 08:00:00']);

        $this->assertSame('2026-10-14 06:45:10', $this->radius->lastSeen('budi@acme'));
    }

    public function test_last_seen_is_null_while_no_session_of_the_user_has_ended(): void
    {
        $this->seedRadiusSession();

        $this->assertNull($this->radius->lastSeen('budi@acme'));
        $this->assertNull($this->radius->lastSeen('nobody@acme'));
    }

    public function test_every_method_refuses_without_a_query_while_switched_off(): void
    {
        config(['services.radius.enabled' => false]);
        $this->radiusConnection()->enableQueryLog();
        $from = new \DateTimeImmutable('2026-10-01');

        $calls = [
            fn () => $this->radius->putUser('budi@acme', self::FAKE_SECRET, 'g'),
            fn () => $this->radius->removeUser('budi@acme'),
            fn () => $this->radius->hasUser('budi@acme', 'g', 'h'),
            fn () => $this->radius->putGroup('g', ['A' => 'b']),
            fn () => $this->radius->putNas('192.0.2.10', 'acme', 'nas-fake-secret', 'd', null),
            fn () => $this->radius->removeNas('192.0.2.10'),
            fn () => $this->radius->openSessions('budi@acme'),
            fn () => $this->radius->sessionsOnNas('192.0.2.10'),
            fn () => $this->radius->usageSince('budi@acme', $from),
            fn () => $this->radius->lastSeen('budi@acme'),
        ];
        foreach ($calls as $call) {
            $this->assertSame(RadiusException::DISABLED, $this->kind($call));
        }
        $this->assertSame([], $this->radiusConnection()->getQueryLog());
    }

    public function test_a_broken_connection_is_unavailable_and_leaks_nothing(): void
    {
        config(['database.connections.radius' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '1',
            'database' => 'fake_radius_db',
            'username' => 'fake_radius_user',
            'password' => 'fake-db-password',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]]);
        DB::purge('radius');

        try {
            $this->radius->putUser('budi@acme', self::FAKE_SECRET, 'acme/home-20m');
            $this->fail('A broken connection must throw.');
        } catch (RadiusException $e) {
            $this->assertSame(RadiusException::UNAVAILABLE, $e->kind);
            foreach (['127.0.0.1', 'fake_radius_user', 'fake_radius_db', 'fake-db-password', self::FAKE_SECRET, 'insert', 'budi@acme'] as $leak) {
                $this->assertStringNotContainsStringIgnoringCase($leak, $e->getMessage());
            }
        }
    }
}
