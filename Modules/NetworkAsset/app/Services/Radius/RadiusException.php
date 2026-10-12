<?php

namespace Modules\NetworkAsset\Services\Radius;

use RuntimeException;

/**
 * A RADIUS database call that was refused or failed. Messages are fixed text: driver errors carry the
 * host, user and bindings, so the original exception is never echoed, only chained as `previous`.
 */
final class RadiusException extends RuntimeException
{
    /** `services.radius.enabled` is off: nothing was sent. */
    public const DISABLED = 'disabled';

    /** The connection or a query failed: retrying later may help. */
    public const UNAVAILABLE = 'unavailable';

    /** The input cannot be stored in the RADIUS schema: retrying cannot help. */
    public const REJECTED = 'rejected';

    public function __construct(public readonly string $kind, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function disabled(): self
    {
        return new self(self::DISABLED, 'RADIUS is switched off.');
    }

    public static function unavailable(?\Throwable $previous = null): self
    {
        return new self(self::UNAVAILABLE, 'The RADIUS database is unavailable.', $previous);
    }

    public static function rejected(string $message): self
    {
        return new self(self::REJECTED, $message);
    }
}
