<?php

namespace App\Support\Observability;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Native log reporter + adapter point for future external central reporter.
 *
 * OWNER BLOCKER: no Sentry/Bugsnag/OTel credentials or provider chosen yet.
 * Wire external reporter only inside {@see self::notifyExternal()}.
 */
final class ExceptionReporter
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function report(Throwable $e, array $context = []): void
    {
        $redacted = LogRedactor::redact($context);

        Log::error($e->getMessage(), array_merge($redacted, [
            'exception' => $e::class,
            // ponytail: stack in log only; never HTTP response
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]));

        $this->notifyExternal($e, $redacted);
    }

    /**
     * Adapter hook for future external reporter (Sentry/Bugsnag/OTel).
     * Intentionally empty until owner provides credentials + provider.
     *
     * @param  array<string, mixed>  $redactedContext
     */
    public function notifyExternal(Throwable $e, array $redactedContext = []): void
    {
        // OWNER BLOCKER: external central reporter not activated.
    }
}
