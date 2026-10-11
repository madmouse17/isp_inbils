<?php

namespace Modules\Billing\Exceptions;

use RuntimeException;

/** Midtrans could not be reached or answered with something unusable. Never carries credentials. */
class MidtransGatewayException extends RuntimeException {}
