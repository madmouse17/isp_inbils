<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\OptionalProp;

/**
 * Options for a server-searched picker (RemoteSearchSelect), sent as an optional Inertia prop that the page
 * reloads on its own with `<field>_search=<text>`.
 *
 * The caller owns tenancy: it passes a base query that is already scoped to the current company (the global scope
 * of a BelongsToCompany model, or a `company_user` join for users, which are exempt from that scope). This helper
 * never widens that query, so a company's options can only hold that company's rows.
 */
final class SearchOptions
{
    public const MIN_CHARS = 2;

    public const LIMIT = 20;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $base  company-scoped query
     * @param  list<string>  $searchColumns  columns matched with LIKE; the first one orders the result unless $base is ordered
     * @param  Closure(TModel): array{value: int|string, label: string, description?: string|null}  $present
     * @param  int|string|null  $selected  key of the current value; its option stays in the list while the user types
     */
    public static function for(
        Request $request,
        string $field,
        Builder $base,
        array $searchColumns,
        Closure $present,
        int|string|null $selected = null,
    ): OptionalProp {
        return Inertia::optional(function () use ($request, $field, $base, $searchColumns, $present, $selected): array {
            // The query string is the caller's: `x_search[]=a` arrives as an array, and only a string is a term.
            $raw = $request->query($field.'_search');
            $term = is_string($raw) ? trim($raw) : '';

            $models = $base->getModel()->newCollection();

            if ($selected !== null && $selected !== '') {
                $models = (clone $base)->whereKey($selected)->limit(1)->get();
            }

            if (mb_strlen($term) >= self::MIN_CHARS) {
                $models = $models->concat(self::matches($base, $searchColumns, $term, $models->modelKeys()));
            }

            return $models->take(self::LIMIT)->map(function (Model $model) use ($present): array {
                $option = $present($model);

                return [
                    'value' => $option['value'],
                    'label' => $option['label'],
                    'description' => $option['description'] ?? null,
                ];
            })->values()->all();
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $base
     * @param  list<string>  $searchColumns
     * @param  array<int, int|string>  $exceptKeys
     * @return Collection<int, TModel>
     */
    private static function matches(Builder $base, array $searchColumns, string $term, array $exceptKeys)
    {
        $pattern = '%'.addcslashes($term, '\\%_').'%';

        $query = clone $base;
        $query->where(function (Builder $group) use ($searchColumns, $pattern): void {
            foreach ($searchColumns as $column) {
                $group->orWhere($column, 'like', $pattern);
            }
        });

        if ($exceptKeys !== []) {
            $query->whereKeyNot($exceptKeys);
        }

        if (empty($query->getQuery()->orders)) {
            $query->orderBy($searchColumns[0])->orderBy($query->getModel()->getQualifiedKeyName());
        }

        $query->limit(self::LIMIT);

        return $query->get();
    }
}
