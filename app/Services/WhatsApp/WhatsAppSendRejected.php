<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * Meta refused this message for good (unknown recipient, template or parameter mismatch, policy ...).
 * Retrying cannot help: the channel marks the message failed and keeps the error code.
 */
final class WhatsAppSendRejected extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('WhatsApp Cloud API rejected the message (code '.$errorCode.').');
    }
}
