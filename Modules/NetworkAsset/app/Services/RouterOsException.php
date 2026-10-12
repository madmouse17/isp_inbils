<?php

namespace Modules\NetworkAsset\Services;

use RuntimeException;

/**
 * A router call that failed. The message is fixed text, or the RouterOS `message`/`detail` of a JSON error
 * body (capped at 255 characters). Any other response body is never echoed: the host is entered by staff.
 */
final class RouterOsException extends RuntimeException
{
    /** Connection failed or timed out. */
    public const UNREACHABLE = 'unreachable';

    /** The router certificate was not accepted. */
    public const TLS = 'tls';

    /** 401/403 (REST) or a refused login (binary API): the API credentials were refused. */
    public const AUTH = 'auth';

    /** A 4xx or a missing address: retrying cannot help. */
    public const REJECTED = 'rejected';

    /** 5xx, a redirect or an answer that is not RouterOS JSON. */
    public const SERVER = 'server';

    /** The installation switch is off: nothing was sent. */
    public const DISABLED = 'disabled';

    /** Set by the RouterOs client: the NAS device the call was for (a job may talk to two routers). */
    public ?int $nasDeviceId = null;

    private function __construct(public readonly string $kind, string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self(self::DISABLED, 'Router provisioning is switched off for this installation.');
    }

    public static function unreachable(): self
    {
        return new self(self::UNREACHABLE, 'The router did not answer (connection failed or timed out).');
    }

    public static function tls(): self
    {
        return new self(self::TLS, 'The router certificate was not accepted (check the CA bundle and the router certificate).');
    }

    /** $status is the HTTP status of the REST API; the binary API has none. */
    public static function auth(?int $status = null): self
    {
        return new self(self::AUTH, 'The router refused the API credentials'.($status === null ? '.' : " (HTTP {$status})."), $status);
    }

    public static function rejected(string $message, ?int $status = null): self
    {
        return new self(self::REJECTED, $message, $status);
    }

    public static function server(string $message, ?int $status = null): self
    {
        return new self(self::SERVER, $message, $status);
    }

    /** Transport and server problems may pass; everything else needs a person. */
    public function retryable(): bool
    {
        return in_array($this->kind, [self::UNREACHABLE, self::TLS, self::SERVER], true);
    }
}
