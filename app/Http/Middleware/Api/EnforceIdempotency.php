<?php

namespace App\Http\Middleware\Api;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A mobile client retries after a timeout, so every mutation carries an Idempotency-Key (a UUID the app keeps for
 * one user action) and a retry never applies twice. Per user and key the cache holds the request fingerprint and,
 * once finished, the response: a replay gets that response, the key with another request is a 422, a duplicate that
 * arrives while the first still runs is a 409. 5xx and 429 are not stored, so those can be retried with the same key.
 */
final class EnforceIdempotency
{
    public const HEADER = 'Idempotency-Key';

    /** Request attribute with the validated, lower-case key, for endpoints that persist it (evidence, material declarations). */
    public const KEY_ATTRIBUTE = 'api.idempotency_key';

    private const REPLAY_SECONDS = 86400;

    /** A worker that dies mid-request frees its key after this, so the client can retry. */
    private const IN_FLIGHT_SECONDS = 120;

    public static function cacheKey(int|string $userId, string $key): string
    {
        return "api.idempotency.{$userId}.{$key}";
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = $this->keyOf($request);
        $request->attributes->set(self::KEY_ATTRIBUTE, $key);

        $cacheKey = self::cacheKey($request->user()->getKey(), $key);
        $fingerprint = self::fingerprint($request);

        // add() is atomic: of two requests racing on one key, exactly one gets to run.
        if (! Cache::add($cacheKey, ['state' => 'in_flight', 'fingerprint' => $fingerprint], self::IN_FLIGHT_SECONDS)) {
            return $this->answerDuplicate(Cache::get($cacheKey), $fingerprint);
        }

        $response = $next($request);
        $body = $response->getContent();

        if ($response->getStatusCode() >= 500 || $response->getStatusCode() === 429 || $body === false) {
            Cache::forget($cacheKey);

            return $response;
        }

        Cache::put($cacheKey, [
            'state' => 'done',
            'fingerprint' => $fingerprint,
            'status' => $response->getStatusCode(),
            'body' => $body,
            'content_type' => $response->headers->get('Content-Type'),
        ], self::REPLAY_SECONDS);

        return $response;
    }

    /** @param  array<string, mixed>|null  $stored */
    private function answerDuplicate(?array $stored, string $fingerprint): Response
    {
        if (($stored['fingerprint'] ?? $fingerprint) !== $fingerprint) {
            throw new ApiException(422, 'idempotency_conflict', 'This Idempotency-Key was already used for a different request.');
        }

        if (($stored['state'] ?? 'in_flight') !== 'done') {
            throw new ApiException(409, 'idempotency_in_progress', 'A request with this Idempotency-Key is still being processed.');
        }

        return response($stored['body'], $stored['status'], array_filter([
            'Content-Type' => $stored['content_type'],
            'Idempotent-Replayed' => 'true',
        ]));
    }

    private function keyOf(Request $request): string
    {
        $key = $request->header(self::HEADER);

        if (! is_string($key) || $key === '') {
            throw new ApiException(428, 'idempotency_key_required', 'The Idempotency-Key header is required for this request.');
        }

        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages([self::HEADER => 'The Idempotency-Key header must be a UUID.']);
        }

        return strtolower($key);
    }

    /**
     * Method, path, query and body. A multipart body is hashed by its fields and the SHA-256 of each file, never
     * by its raw bytes: the boundary changes on every attempt, the photo does not. Public because the web
     * double-submit guard (PreventDuplicateWebSubmit) recognises an identical request the same way.
     */
    public static function fingerprint(Request $request): string
    {
        $multipart = $request->allFiles() !== [] || str_contains(strtolower((string) $request->header('Content-Type')), 'multipart/');

        $body = $multipart
            ? json_encode([
                $request->post(),
                array_map(fn (mixed $file): string => $file instanceof UploadedFile && $file->isValid() ? (string) hash_file('sha256', $file->getRealPath()) : '', Arr::flatten($request->allFiles())),
            ])
            // A request built from an array (the test client) has no raw body: its fields stand in, so two different forms never match.
            : ($request->getContent() ?: json_encode($request->post()));

        return hash('sha256', implode("\n", [$request->method(), $request->getPathInfo(), (string) $request->getQueryString(), hash('sha256', $body)]));
    }
}
