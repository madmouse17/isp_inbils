<?php

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;
use SplPriorityQueue;
use Throwable;

/**
 * Everything the demo company "did" is a dated event. Events run oldest first with the clock set to
 * their moment, so ids, document numbers, stock balances and timestamps all agree with the story.
 * An event may schedule later events (a payment after the invoice that created it exists).
 */
final class DemoTimeline
{
    private readonly SplPriorityQueue $queue;

    private int $sequence = 0;

    private ?CarbonImmutable $current = null;

    public function __construct(private readonly DemoContext $context)
    {
        $this->queue = new SplPriorityQueue();
        $this->queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
    }

    /** @param Closure(DemoContext): mixed $do */
    public function at(CarbonImmutable $when, Closure $do, string $label = ''): void
    {
        $when = $this->context->clamp($when);

        if ($this->current !== null && $when->lessThan($this->current)) {
            $when = $this->current;
        }

        // Earlier first; the sequence number keeps events of one moment in the order they were planned.
        $this->queue->insert([$when, $do, $label], [-$when->getTimestamp(), -(++$this->sequence)]);
    }

    /** Starts a chain of steps that follow one another from $start. */
    public function chain(CarbonImmutable $start): DemoChain
    {
        return new DemoChain($this->context, $this, $start);
    }

    public function run(): void
    {
        while (! $this->queue->isEmpty()) {
            [$when, $do, $label] = $this->queue->extract();
            $this->current = $when;
            $this->context->travel($when);

            try {
                $do($this->context);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    "Demo event [{$label}] at {$when->toDateTimeString()} failed: {$e->getMessage()}",
                    0,
                    $e,
                );
            }
        }

        $this->current = null;
    }
}
