<?php

namespace Modules\NetworkAsset\Services\Radius;

final readonly class DisconnectResult
{
    /** Error-Cause "Session-Context-Not-Found" (RFC 5176 §3.5): the NAS no longer holds the session. */
    public const SESSION_NOT_FOUND = 503;

    /** @param 'ack'|'nak'|'timeout'|'invalid' $status */
    public function __construct(public string $status, public ?int $errorCause = null) {}

    public function isDone(): bool
    {
        return $this->status === 'ack'
            || ($this->status === 'nak' && $this->errorCause === self::SESSION_NOT_FOUND);
    }
}
