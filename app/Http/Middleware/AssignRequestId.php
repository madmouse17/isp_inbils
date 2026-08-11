<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->resolveId($request);

        $request->attributes->set('request_id', $id);
        Log::shareContext(['request_id' => $id]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }

    private function resolveId(Request $request): string
    {
        $candidates = [
            $request->headers->get('X-Request-Id'),
            $request->headers->get('X-Correlation-Id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $this->isSafe($candidate)) {
                return $candidate;
            }
        }

        return (string) Str::uuid();
    }

    private function isSafe(string $value): bool
    {
        // printable ASCII, no CR/LF, reasonable length
        return $value !== ''
            && strlen($value) <= 128
            && preg_match('/^[\x20-\x7E]+$/', $value) === 1;
    }
}
