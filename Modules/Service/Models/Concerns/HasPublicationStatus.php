<?php

namespace Modules\Service\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The lifecycle announcements and promo banners share: a draft until published, then live inside its period unless
 * deactivated. `status()` and `scopeOfStatus()` are one rule in two forms and must agree on the edges: an item that
 * starts exactly now is live, one that ends exactly now is ended, and a deactivated one is inactive whatever its period.
 */
trait HasPublicationStatus
{
    /** @param Builder<self> $query */
    public function scopePublishedAndLive(Builder $query): Builder
    {
        return $query
            ->whereNotNull('published_at')
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $period) => $period->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /**
     * @param  Builder<self>  $query
     * @param  string  $status  one of Announcement::STATUSES
     */
    public function scopeOfStatus(Builder $query, string $status): Builder
    {
        // A NULL ends_at fails the comparison, so an open-ended item is never "ended".
        return match ($status) {
            'draft' => $query->whereNull('published_at'),
            'inactive' => $query->whereNotNull('published_at')->where('is_active', false),
            'scheduled' => $query->whereNotNull('published_at')->where('is_active', true)->where('starts_at', '>', now()),
            'live' => $query->publishedAndLive(),
            'ended' => $query->whereNotNull('published_at')->where('is_active', true)->where('starts_at', '<=', now())->where('ends_at', '<=', now()),
            default => throw new InvalidArgumentException("Unknown status {$status}."),
        };
    }

    public function status(): string
    {
        if ($this->published_at === null) {
            return 'draft';
        }

        if (! $this->is_active) {
            return 'inactive';
        }

        if ($this->starts_at->isFuture()) {
            return 'scheduled';
        }

        return $this->ends_at !== null && ! $this->ends_at->isFuture() ? 'ended' : 'live';
    }
}
