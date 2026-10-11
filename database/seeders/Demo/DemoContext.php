<?php

namespace Database\Seeders\Demo;

use App\Models\Core\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use OutOfBoundsException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * State of one DemoDataSeeder run: the company, the demo users that act, the ids earlier steps
 * created, a seeded randomizer (same input, same world) and the clock the timeline drives.
 */
final class DemoContext
{
    /** Days of history the demo company has. */
    public const HISTORY_DAYS = 178;

    /** Office hours are written in WIB; every stored instant stays in the application timezone. */
    private const OFFICE_TIMEZONE = 'Asia/Jakarta';

    public readonly CarbonImmutable $now;

    public readonly CarbonImmutable $start;

    public readonly DemoTimeline $timeline;

    private readonly Randomizer $randomizer;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, array<string, mixed>> */
    private array $registry = [];

    public function __construct(public readonly Company $company)
    {
        $this->now = CarbonImmutable::now()->startOfSecond();
        $this->start = $this->now->subDays(self::HISTORY_DAYS)->startOfDay();
        $this->randomizer = new Randomizer(new Mt19937(20261011));
        $this->timeline = new DemoTimeline($this);
    }

    public function companyId(): int
    {
        return (int) $this->company->id;
    }

    public function addUser(string $key, User $user): void
    {
        $this->users[$key] = $user;
    }

    public function user(string $key): User
    {
        return $this->users[$key] ?? throw new OutOfBoundsException("Unknown demo user [{$key}].");
    }

    /**
     * Runs $callback signed in as a demo user (key or model), so maker-checker guards see a real actor.
     *
     * @template T
     *
     * @param  Closure(User): T  $callback
     * @return T
     */
    public function as(string|User $actor, Closure $callback): mixed
    {
        $user = $actor instanceof User ? $actor : $this->user($actor);
        $previous = Auth::user();
        Auth::setUser($user);

        try {
            return $callback($user);
        } finally {
            $previous !== null ? Auth::setUser($previous) : Auth::forgetGuards();
        }
    }

    public function put(string $kind, string $key, mixed $value): void
    {
        $this->registry[$kind][$key] = $value;
    }

    public function has(string $kind, string $key): bool
    {
        return array_key_exists($key, $this->registry[$kind] ?? []);
    }

    public function get(string $kind, string $key): mixed
    {
        return $this->has($kind, $key)
            ? $this->registry[$kind][$key]
            : throw new OutOfBoundsException("Nothing registered as {$kind}:{$key}.");
    }

    /** @return array<string, mixed> */
    public function all(string $kind): array
    {
        return $this->registry[$kind] ?? [];
    }

    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    public function chance(int $percent): bool
    {
        return $this->int(1, 100) <= $percent;
    }

    /**
     * @template T
     *
     * @param  non-empty-list<T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }

    /** @param array<string, int> $weights */
    public function weighted(array $weights): string
    {
        $roll = $this->int(1, array_sum($weights));

        foreach ($weights as $key => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }

    /** A moment on the office clock, $daysAgo days before today. Never later than the seeding moment. */
    public function day(int $daysAgo, int $hour = 9, int $minute = 0): CarbonImmutable
    {
        return $this->clamp(
            $this->now->setTimezone(self::OFFICE_TIMEZONE)->startOfDay()->subDays($daysAgo)->setTime($hour, $minute)
        );
    }

    /** A moment on the office clock, $days days after $from, at the given hour. */
    public function dayAfter(CarbonImmutable $from, int $days, int $hour, int $minute = 0): CarbonImmutable
    {
        return $this->clamp($from->setTimezone(self::OFFICE_TIMEZONE)->addDays($days)->setTime($hour, $minute));
    }

    public function clamp(CarbonImmutable $when): CarbonImmutable
    {
        $latest = $this->now->subMinutes(2);

        return $when->setTimezone($this->now->getTimezone())->greaterThan($latest)
            ? $latest
            : $when->setTimezone($this->now->getTimezone());
    }

    /** Moves the clock every `now()` in a service reads. */
    public function travel(CarbonImmutable $when): void
    {
        Carbon::setTestNow($when);
        CarbonImmutable::setTestNow($when);
    }
}
