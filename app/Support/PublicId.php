<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Ulid;

/**
 * The format of `public_id`: a lowercase ULID. HasPublicId stores it through the model; the code that writes rows
 * without one (seeders, bulk inserts, the backfill migrations, package models) takes it from here so the format
 * cannot drift. It also turns the public ids a client sends back into the integer ids everything behind the
 * request boundary works with.
 */
final class PublicId
{
    public static function new(): string
    {
        // Not Str::ulid(): Symfony counts up inside one millisecond, so ids made together (a backfill, a seeder, a batch
        // of invoices) would be neighbours and one leaked id would name its siblings. The random part is fresh per id.
        $timestamp = substr(pack('J', (int) (microtime(true) * 1000)), 2);

        return strtolower((string) Ulid::fromString($timestamp.random_bytes(10)));
    }

    /**
     * Values for `updateOrInsert()` that give an inserted row its id and leave the id of an existing row alone.
     *
     * @param  array<string, mixed>  $values
     * @return Closure(bool): array<string, mixed>
     */
    public static function onInsert(array $values): Closure
    {
        return fn (bool $exists): array => $exists ? $values : [...$values, 'public_id' => self::new()];
    }

    /**
     * The integer ids behind public ids a client sent, in one query. Only a ULID string reaches the database: an
     * integer, an array or any other string would be compared by MySQL as a number and could match a row it does
     * not name. The query is the model's own, so a company model answers for the current company only;
     * `$constrain` adds what the field requires on top (active, not trashed).
     *
     * @param  class-string<Model>  $model
     * @param  iterable<mixed>  $values
     * @param  (Closure(Builder<Model>): mixed)|null  $constrain
     * @return array<string, int> lowercase public id => integer id, for the values that name a record
     */
    public static function resolve(string $model, iterable $values, ?Closure $constrain = null): array
    {
        $ulids = [];

        foreach ($values as $value) {
            if (is_string($value) && Str::isUlid($value)) {
                $ulids[strtolower($value)] = true;
            }
        }

        if ($ulids === []) {
            return [];
        }

        $query = $model::query();
        $constrain?->__invoke($query);
        $instance = $query->getModel();

        return $query
            ->whereIn($instance->qualifyColumn('public_id'), array_keys($ulids))
            ->pluck($instance->getQualifiedKeyName(), $instance->qualifyColumn('public_id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  class-string<Model>  $model
     * @param  (Closure(Builder<Model>): mixed)|null  $constrain
     */
    public static function resolveOne(string $model, mixed $value, ?Closure $constrain = null): ?int
    {
        return self::resolve($model, [$value], $constrain)[strtolower((string) (is_string($value) ? $value : ''))] ?? null;
    }

    /**
     * The public id of the record each (type, id) pair names, one query per type. Several columns keep a short type
     * name next to an integer id (a WhatsApp message subject, a stock movement), which no morph relation resolves.
     *
     * @param  array<string, class-string<Model>>  $models  type name => model
     * @param  iterable<array{0: mixed, 1: mixed}>  $pairs
     * @return array<string, string> "type:id" => public id
     */
    public static function ofPairs(array $models, iterable $pairs): array
    {
        $wanted = [];

        foreach ($pairs as [$type, $id]) {
            if (is_string($type) && isset($models[$type]) && is_numeric($id)) {
                $wanted[$type][(int) $id] = true;
            }
        }

        $found = [];

        foreach ($wanted as $type => $ids) {
            $model = $models[$type];

            foreach ($model::query()->withoutGlobalScopes()->whereIn($model::query()->getModel()->getQualifiedKeyName(), array_keys($ids))->get(['id', 'public_id']) as $record) {
                $found[$type.':'.$record->getKey()] = (string) $record->getAttribute('public_id');
            }
        }

        return $found;
    }
}
