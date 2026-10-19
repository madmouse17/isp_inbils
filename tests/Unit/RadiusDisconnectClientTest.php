<?php

namespace Tests\Unit;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\NetworkAsset\Services\Radius\DisconnectResult;
use Modules\NetworkAsset\Services\Radius\RadiusDisconnectClient;
use Modules\NetworkAsset\Services\Radius\RadiusException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\InteractsWithRadius;

/**
 * Byte vectors were computed once with an independent implementation (Python hashlib + struct, RFC 5176 §2.3
 * and §3: MD5 over code, identifier, length, authenticator field, attributes, secret) with the fake secret
 * below. They are not produced by the code under test.
 */
class RadiusDisconnectClientTest extends TestCase
{
    use InteractsWithRadius;
    use RefreshDatabase;

    private const SECRET = 'fake-secret';

    private const REQUEST = '2807003065e067f39b28b66a78417d56713f0bbe010c616c6963654061636d652c0a38313030303030310406c000020a';

    private const ACK = '29070014db77c86fdc9bd6c00b2810905caf2799';

    private const NAK_503 = '2a07001a60df172e27e5d7fdbaa55ac24e1b04136506000001f7';

    private function attributes(): array
    {
        return ['User-Name' => 'alice@acme', 'Acct-Session-Id' => '81000001', 'NAS-IP-Address' => '192.0.2.10'];
    }

    private function requestAuthenticator(): string
    {
        return substr(hex2bin(self::REQUEST), 4, 16);
    }

    /** A reply signed with the real algorithm, to craft variants (other causes, other ids). */
    private function reply(int $code, int $id, string $attributes, string $secret = self::SECRET): string
    {
        $head = pack('CCn', $code, $id, 20 + strlen($attributes));

        return $head.md5($head.$this->requestAuthenticator().$attributes.$secret, true).$attributes;
    }

    /** The sender asks the RADIUS row of the company it acts for: put one in scope, with a row unless told not to. */
    private function actAsACompany(bool $withRadius = true): void
    {
        if ($withRadius) {
            $this->fakeRadius();
        }

        CompanyService::useCompanyForTests((int) Company::factory()->create(['is_active' => true])->id);
    }

    public function test_encode_matches_independent_vector(): void
    {
        $packet = (new RadiusDisconnectClient())->encode(7, $this->attributes(), self::SECRET);

        $this->assertSame(self::REQUEST, bin2hex($packet));
    }

    public function test_decode_ack(): void
    {
        $result = (new RadiusDisconnectClient())->decode(hex2bin(self::ACK), $this->requestAuthenticator(), self::SECRET, 7);

        $this->assertSame('ack', $result->status);
        $this->assertNull($result->errorCause);
        $this->assertTrue($result->isDone());
    }

    public function test_decode_nak_session_not_found_is_done(): void
    {
        $result = (new RadiusDisconnectClient())->decode(hex2bin(self::NAK_503), $this->requestAuthenticator(), self::SECRET, 7);

        $this->assertSame('nak', $result->status);
        $this->assertSame(503, $result->errorCause);
        $this->assertTrue($result->isDone());
    }

    public function test_decode_nak_other_cause_is_not_done(): void
    {
        $packet = $this->reply(42, 7, pack('CCN', 101, 6, 404));

        $result = (new RadiusDisconnectClient())->decode($packet, $this->requestAuthenticator(), self::SECRET, 7);

        $this->assertSame('nak', $result->status);
        $this->assertSame(404, $result->errorCause);
        $this->assertFalse($result->isDone());
    }

    public function test_decode_nak_without_cause_is_not_done(): void
    {
        $result = (new RadiusDisconnectClient())->decode($this->reply(42, 7, ''), $this->requestAuthenticator(), self::SECRET, 7);

        $this->assertSame('nak', $result->status);
        $this->assertNull($result->errorCause);
        $this->assertFalse($result->isDone());
    }

    public static function invalidReplies(): array
    {
        return [
            'wrong identifier' => [fn (self $t) => $t->reply(41, 8, '')],
            'forged (wrong secret)' => [fn (self $t) => $t->reply(41, 7, '', 'other-secret')],
            'unknown code' => [fn (self $t) => $t->reply(43, 7, '')],
            'request echoed back (code 40)' => [fn () => hex2bin(self::REQUEST)],
            'too short' => [fn () => 'abc'],
            'tampered attribute' => [function (self $t) {
                $packet = $t->reply(42, 7, pack('CCN', 101, 6, 503));

                return substr($packet, 0, -1).chr(ord(substr($packet, -1)) ^ 1);
            }],
            'length field beyond datagram' => [function () {
                $packet = hex2bin(self::ACK);

                return substr_replace($packet, pack('n', 99), 2, 2);
            }],
        ];
    }

    #[DataProvider('invalidReplies')]
    public function test_decode_rejects_invalid_reply(\Closure $build): void
    {
        $result = (new RadiusDisconnectClient())->decode($build($this), $this->requestAuthenticator(), self::SECRET, 7);

        $this->assertSame('invalid', $result->status);
        $this->assertFalse($result->isDone());
    }

    public function test_times_out_when_nobody_answers(): void
    {
        $this->actAsACompany();
        config(['services.radius.coa_timeout' => 1]);
        $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);

        $started = microtime(true);
        $result = (new RadiusDisconnectClient())->disconnect('127.0.0.1', $port, self::SECRET, $this->attributes());
        fclose($socket);

        $this->assertSame('timeout', $result->status);
        $this->assertLessThanOrEqual(2.0, microtime(true) - $started);
    }

    /** @return array<string, array{0: bool}> */
    public static function companiesWithoutAnEnabledRadius(): array
    {
        return ['no row at all' => [false], 'a row switched off' => [true]];
    }

    #[DataProvider('companiesWithoutAnEnabledRadius')]
    public function test_a_company_without_an_enabled_radius_refuses_without_opening_a_socket(bool $hasRow): void
    {
        $this->actAsACompany($hasRow);

        if ($hasRow) {
            $this->disableRadius();
        }

        // Port 1 would refuse or time out; a refusal before any socket work is the only way to get the exception.
        try {
            (new RadiusDisconnectClient())->disconnect('127.0.0.1', 1, self::SECRET, $this->attributes());
            $this->fail('RadiusException expected');
        } catch (RadiusException $e) {
            $this->assertSame(RadiusException::DISABLED, $e->kind);
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    public function test_is_done_only_for_ack_or_nak_503(): void
    {
        $this->assertTrue((new DisconnectResult('ack'))->isDone());
        $this->assertTrue((new DisconnectResult('nak', 503))->isDone());
        $this->assertFalse((new DisconnectResult('nak', 404))->isDone());
        $this->assertFalse((new DisconnectResult('timeout'))->isDone());
        $this->assertFalse((new DisconnectResult('invalid'))->isDone());
    }
}
