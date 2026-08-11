<?php

namespace App\Listeners;

use App\Support\Observability\ExceptionReporter;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

class ReportFailedQueueJob
{
    public function __construct(private readonly ExceptionReporter $reporter) {}

    public function handle(JobFailed $event): void
    {
        $job = $event->job;

        $context = [
            'job' => $job->resolveName(),
            'uuid' => $job->uuid(),
            'connection' => $event->connectionName,
            'queue' => $job->getQueue(),
            'attempts' => $job->attempts(),
        ];

        // Surface serialised job props so LogRedactor can scrub secrets.
        $command = $job->payload()['data']['command'] ?? null;
        if (is_string($command)) {
            $object = @unserialize($command, ['allowed_classes' => true]);
            if (is_object($object)) {
                foreach (get_object_vars($object) as $key => $value) {
                    if (is_scalar($value) || $value === null) {
                        $context[$key] = $value;
                    }
                }
            }
        }

        $this->reporter->report($event->exception, $context);

        // ponytail: log channel only until owner picks SMS/WhatsApp/pager
        Log::channel(config('logging.default'))->warning('queue.job_failed', [
            'job' => $context['job'],
            'uuid' => $context['uuid'],
            'connection' => $context['connection'],
        ]);
    }
}
