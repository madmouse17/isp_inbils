<?php

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Closure;

/** Plans steps that follow one another: each `then*` call moves the cursor on and schedules the step there. */
final class DemoChain
{
    private CarbonImmutable $cursor;

    public function __construct(
        private readonly DemoContext $context,
        private readonly DemoTimeline $timeline,
        CarbonImmutable $start,
    ) {
        $this->cursor = $start;
    }

    public function cursor(): CarbonImmutable
    {
        return $this->cursor;
    }

    /** Lets time pass without a step, so the next step does not share a minute with the last. */
    public function wait(int $minutes): self
    {
        $this->cursor = $this->cursor->addMinutes($minutes);

        return $this;
    }

    /** @param Closure(DemoContext): mixed $do */
    public function step(Closure $do, string $label = ''): self
    {
        $this->timeline->at($this->cursor, $do, $label);

        return $this;
    }

    /** @param Closure(DemoContext): mixed $do */
    public function afterMinutes(int $minutes, Closure $do, string $label = ''): self
    {
        $this->cursor = $this->cursor->addMinutes($minutes);

        return $this->step($do, $label);
    }

    /** @param Closure(DemoContext): mixed $do */
    public function afterHours(int $hours, Closure $do, string $label = ''): self
    {
        return $this->afterMinutes($hours * 60 + $this->context->int(0, 40), $do, $label);
    }

    /**
     * The step happens on an office day $days after the cursor, at a jittered hour of work.
     *
     * @param  Closure(DemoContext): mixed  $do
     */
    public function afterDays(int $days, Closure $do, string $label = '', int $fromHour = 8, int $toHour = 15): self
    {
        $this->cursor = $this->context->dayAfter(
            $this->cursor,
            $days,
            $this->context->int($fromHour, $toHour),
            $this->context->int(0, 59),
        );

        return $this->step($do, $label);
    }
}
