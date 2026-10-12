<?php

namespace Modules\NetworkAsset\Services\Radius;

use RuntimeException;

/**
 * A Disconnect-Request got no usable answer (timeout, refusal, forged reply): the run is retried with backoff.
 * Messages name the NAS by asset code only, never an address, packet or secret.
 */
final class DisconnectPendingException extends RuntimeException {}
