<?php

namespace App\Traits;

use App\Support\PublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Str;

/**
 * The one identifier of a record that leaves the server: a ULID in `public_id` (spec .scratch/opaque-ids). The
 * integer `id` stays the primary key and every foreign key, so queries and services keep their integer keys; URLs,
 * route parameters and payloads carry `public_id`. HasUlids fills the column on create and answers 404 for a route
 * value that is not a ULID, so an integer id never reaches the database.
 *
 * @property string $public_id
 */
trait HasPublicId
{
    use HasUlids;

    public function newUniqueId(): string
    {
        return PublicId::new();
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * The record a public id names. Anything that is not a ULID names nothing: MySQL would compare an integer or a
     * numeric-looking string with the column as a number and could match an unrelated row.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWherePublicId(Builder $query, mixed $publicId): Builder
    {
        return Str::isUlid($publicId) ? $query->where($this->qualifyColumn('public_id'), $publicId) : $query->whereRaw('0 = 1');
    }
}
