<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The one renderer of every exception under api/*: {message, code, errors?} and nothing of the exception
 * itself (no class, SQL or trace) whatever APP_DEBUG says. Registered ahead of the web renderers in
 * bootstrap/app.php, so a 409 or an InsufficientStockException never answers an app with a redirect.
 *
 * The app shows its own text per `code`; `message` is for developers and logs.
 */
final class RenderApiError
{
    /** Stable for the apps; a status without an entry falls back to client_error or server_error. */
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        408 => 'request_timeout',
        409 => 'conflict',
        413 => 'payload_too_large',
        415 => 'unsupported_media_type',
        422 => 'unprocessable',
        428 => 'precondition_required',
        429 => 'too_many_requests',
    ];

    /**
     * @param  list<class-string<Throwable>>  $unprocessable  domain refusals of a well-formed request: 422, message shown
     */
    public function __construct(private readonly array $unprocessable = []) {}

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        // A response that was already built (a deliberate redirect, say) is not ours to reshape.
        if (! $request->is('api/*') || $e instanceof HttpResponseException) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => $this->json(422, 'validation_failed', $e->getMessage(), $e->errors()),
            $e instanceof AuthenticationException => $this->json(401, 'unauthenticated', 'Unauthenticated.'),
            $e instanceof ApiException => $this->json($e->getStatusCode(), $e->errorCode, $e->getMessage(), headers: $e->getHeaders()),
            $e instanceof HttpExceptionInterface => $this->http($e),
            $this->isUnprocessable($e) => $this->json(422, 'unprocessable', $e->getMessage()),
            default => $this->json(500, 'server_error', 'Server Error'),
        };
    }

    private function http(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();

        // A missing model reaches here as a 404 whose message names the model class.
        $message = match (true) {
            $status === 404 => 'Not found.',
            $status >= 500 => 'Server Error',
            default => $e->getMessage() ?: (Response::$statusTexts[$status] ?? 'Error'),
        };

        return $this->json($status, self::CODES[$status] ?? ($status >= 500 ? 'server_error' : 'client_error'), $message, headers: $e->getHeaders());
    }

    private function isUnprocessable(Throwable $e): bool
    {
        foreach ($this->unprocessable as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, string>  $headers
     */
    private function json(int $status, string $code, string $message, array $errors = [], array $headers = []): JsonResponse
    {
        return response()->json(
            ['message' => $message, 'code' => $code] + ($errors === [] ? [] : ['errors' => $errors]),
            $status,
            $headers,
        );
    }
}
