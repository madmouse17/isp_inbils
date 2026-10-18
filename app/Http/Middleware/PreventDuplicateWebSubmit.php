<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Api\EnforceIdempotency;
use App\Services\Core\CompanyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A double click or a retried tab sends the same write twice. An identical write (method, path, query and body, see
 * EnforceIdempotency::fingerprint) to an admin route by the same user in the same company inside SECONDS is refused
 * with the shared 409 (bootstrap/app.php): the Inertia page gets errors.conflict and the global error toast, a JSON
 * client gets {message, error: conflict}. A write that was itself refused (4xx, validation errors) frees its key.
 * config/security.php (duplicate_submit.enabled) switches it off in `testing`; a test that needs it switches it on.
 */
final class PreventDuplicateWebSubmit
{
    public const SECONDS = 10;

    public const MESSAGE = 'Permintaan yang sama baru saja dikirim.';

    /** Admin writes that may repeat with an identical body inside the window. Route files belong to other lanes, so the list lives here (precedent: VerifyCsrfToken::$except). */
    private const EXCEPT = [
        'admin.company.switch',                                      // switching back and forth is intended
        'admin.notifications.read', 'admin.notifications.read-all',  // idempotent
        'admin.invoices.generate-preview',                           // a read sent as POST (GenerateDialog.tsx, axios)
        'admin.subscriptions.network-account.reveal',                // a read sent as POST, audited by the controller
        'admin.company.integrations.test',                           // "Uji koneksi", has its own throttle
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('security.duplicate_submit.enabled')
            || $user === null
            || $request->isMethodSafe()
            || ! $request->routeIs('admin.*')
            || $request->routeIs(...self::EXCEPT)) {
            return $next($request);
        }

        $key = "web.dup.{$user->getKey()}.".CompanyService::currentId().'.'.EnforceIdempotency::fingerprint($request);

        // add() is atomic: of two identical requests racing, exactly one gets to run.
        if (! Cache::add($key, true, self::SECONDS)) {
            throw new ConflictHttpException(self::MESSAGE);
        }

        $response = $next($request);

        // A refused attempt did not happen, so an identical retry must reach the controller.
        if ($response->getStatusCode() >= 400 || $request->session()->has('errors')) {
            Cache::forget($key);
        }

        return $response;
    }
}
