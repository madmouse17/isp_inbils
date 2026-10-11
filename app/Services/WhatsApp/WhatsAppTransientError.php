<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * Meta or the network failed in a way that can heal (5xx, timeout, throttling, a token the owner has not
 * replaced yet). The queue retries; the message stays queued with this code. The message never carries a
 * response body: it could hold a phone number.
 */
final class WhatsAppTransientError extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('WhatsApp Cloud API is not accepting messages right now (code '.$errorCode.').');
    }
}
