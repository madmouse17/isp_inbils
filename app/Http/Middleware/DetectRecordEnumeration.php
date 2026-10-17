<?php

namespace App\Http\Middleware;

use App\Services\Core\AuditService;
use App\Support\RecordNotFound;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notices someone walking the ids (spec .scratch/opaque-ids K4). It counts the record-level 404s a signed-in user gets:
 * a route-model binding miss, `findOrFail()`, RecordNotFound, a policy that hides the record. A missing permission
 * (403) and any other 404 (an unknown URL) do not count. Past `security.enumeration.threshold` within `window`
 * minutes it writes one `security.enumeration_suspected` audit entry (route name and the ids tried, nothing else) and
 * answers 429 for `cooldown` minutes.
 *
 * Nobody who is signed out is counted. Every record route sits behind the login (the isolir page has its own
 * limiter), and an address is not a person: counting by it would let anyone lock /login out for everybody who shares
 * that address, a NAT or a proxy.
 *
 * The window is rolling at one-minute granularity: one counter per minute, the window is the last N of them. It runs
 * ahead of route model binding (the priority list in bootstrap/app.php) and after the guards, so the user is known.
 */
final class DetectRecordEnumeration
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.enumeration.enabled')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $actor = $this->actor($user);

        $this->refuseWhileBlocked($actor);

        $response = $next($request);

        if ($this->isRecordMiss($response) && $this->count($actor) > (int) config('security.enumeration.threshold')) {
            $this->block($request, $actor);
            $this->refuseWhileBlocked($actor);
        }

        return $response;
    }

    private function actor(Authenticatable $user): string
    {
        return 'user:'.$user::class.':'.$user->getAuthIdentifier();
    }

    private function isRecordMiss(Response $response): bool
    {
        // The pipeline renders an exception into this response and keeps the exception on it.
        $exception = $response->exception ?? null;

        return $response->getStatusCode() === 404
            && ($exception instanceof ModelNotFoundException
                || $exception instanceof RecordNotFound
                || ($exception instanceof AuthorizationException && $exception->status() === 404));
    }

    private function count(string $actor): int
    {
        $window = max(1, (int) config('security.enumeration.window'));
        $minute = intdiv(now()->getTimestamp(), 60);
        $current = $this->counterKey($actor, $minute);

        Cache::add($current, 0, ($window + 1) * 60);
        Cache::increment($current);

        $total = 0;

        for ($ago = 0; $ago < $window; $ago++) {
            $total += (int) Cache::get($this->counterKey($actor, $minute - $ago), 0);
        }

        return $total;
    }

    /** Only the request that wins the lock writes the audit entry, so a burst of misses logs once. */
    private function block(Request $request, string $actor): void
    {
        $cooldown = max(1, (int) config('security.enumeration.cooldown')) * 60;

        if (! Cache::add($this->blockKey($actor), now()->getTimestamp() + $cooldown, $cooldown)) {
            return;
        }

        $user = $request->user();

        AuditService::log('security', 'enumeration_suspected', [
            'route' => $request->route()?->getName(),
            'ids' => $this->idsTried($request),
            'count' => (int) config('security.enumeration.threshold') + 1,
            'window_minutes' => (int) config('security.enumeration.window'),
            'ip' => $request->ip(),
        ], causer: $user instanceof Model ? $user : null);
    }

    private function refuseWhileBlocked(string $actor): void
    {
        $until = Cache::get($this->blockKey($actor));

        if ($until !== null) {
            abort(429, 'Terlalu banyak permintaan. Coba lagi dalam beberapa menit.', ['Retry-After' => max(1, (int) $until - now()->getTimestamp())]);
        }
    }

    /**
     * The route parameters of the request that crossed the line, as text: a model is named by its public id.
     *
     * @return array<string, string>
     */
    private function idsTried(Request $request): array
    {
        $ids = [];

        foreach ($request->route()?->parameters() ?? [] as $name => $value) {
            $value = $value instanceof Model ? $value->getAttribute('public_id') : $value;

            if (is_scalar($value)) {
                $ids[$name] = mb_substr((string) $value, 0, 64);
            }
        }

        return $ids;
    }

    private function counterKey(string $actor, int $minute): string
    {
        return 'enumeration:'.$actor.':'.$minute;
    }

    private function blockKey(string $actor): string
    {
        return 'enumeration:blocked:'.$actor;
    }
}
