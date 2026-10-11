<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A refusal the mobile apps tell apart by a stable snake_case code, not by status or text: the
 * response is {message, code} (see RenderApiError). Throw it from API code only; the message is
 * shown to developers, the app maps the code to its own text.
 */
class ApiException extends HttpException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(int $status, public readonly string $errorCode, string $message = '', array $headers = [])
    {
        parent::__construct($status, $message, null, $headers);
    }
}
