<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->resolveId($request);

        $request->attributes->set('request_id', $id);
        // The Context reaches every log line (extra.request_id), the queued jobs this request dispatches and the activity rows.
        Context::add('request_id', $id);

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
        // Token characters only: the id is stored on audit rows and sent to vendors, so a client cannot choose anything free-form.
        return preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $value) === 1;
    }
}
