<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What a master record shares so that it is deactivated, restored, and deleted only while nothing points at it (spec
 * .scratch/master-lifecycle): the status filter, the rows that reference it, and the resource fields behind the
 * Nonaktifkan, Pulihkan and Hapus permanen actions. LifecycleService does the writes. The model also uses
 * SoftDeletes and HasPublicId.
 *
 * @mixin Model
 *
 * @phpstan-require-extends Model
 */
trait HasLifecycle
{
    /** ?status= values of a master with an is_active flag. */
    public const ACTIVE_FILTERS = ['aktif' => ['is_active', true], 'nonaktif' => ['is_active', false]];

    /** Sentence-case noun for messages and dialogs: 'Profil bandwidth'. */
    abstract public static function lifecycleNoun(): string;

    /**
     * ?status= values besides `dihapus` and `semua`: value => [column, stored value]. Masters with is_active include
     * self::ACTIVE_FILTERS; entities with a lifecycle column add self::statusFilters('status', self::STATUSES).
     *
     * @return array<string, array{0: string, 1: bool|string}>
     */
    abstract public static function lifecycleFilters(): array;

    /**
     * Masters: label => list of "table.column" (a JSON path is allowed: "media.custom_properties->document_type_id")
     * whose rows keep this record from a hard delete. It must cover every RESTRICT, NO ACTION and SET NULL foreign key
     * to the table (LifecycleReferencesMatchSchemaTest); cascade children that are history are listed too. Null: the
     * record keeps its own soft-delete rules (tickets, SPK, invoices, employees, evaluations).
     *
     * shortcut: a JSON path cannot use an index, so each row of a listing scans that table once; index a generated
     * column when the table outgrows a page of rows.
     *
     * @return array<string, list<string>>|null
     */
    abstract public static function lifecycleReferences(): ?array;

    public static function isDeactivatable(): bool
    {
        return array_key_exists('aktif', static::lifecycleFilters());
    }

    /**
     * @param  list<string>  $values
     * @return array<string, array{0: string, 1: string}>
     */
    protected static function statusFilters(string $column, array $values): array
    {
        return array_combine($values, array_map(static fn (string $value): array => [$column, $value], $values));
    }

    /**
     * An empty or unknown value is the default view, where SoftDeletes hides the deleted rows. `dihapus` shows only
     * those, `semua` shows all, and a key of lifecycleFilters() narrows by its column.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLifecycleStatus(Builder $query, mixed $status): Builder
    {
        if ($status === 'dihapus') {
            return $query->onlyTrashed();
        }

        if ($status === 'semua') {
            return $query->withTrashed();
        }

        $filter = is_string($status) ? (static::lifecycleFilters()[$status] ?? null) : null;

        return $filter === null ? $query : $query->where($this->qualifyColumn($filter[0]), $filter[1]);
    }

    /**
     * One correlated COUNT(*) per reference column, aliased `reference_{label index}_{column index}`. It reads the
     * query builder, so trashed children count. The inner table is aliased `lifecycle_ref`: a self-reference such as
     * locations.parent_id would otherwise compare a row with itself.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithReferenceCounts(Builder $query): Builder
    {
        $references = static::lifecycleReferences();

        if ($references === null) {
            return $query;
        }

        if ($query->getQuery()->columns === null) {
            $query->select($this->qualifyColumn('*'));
        }

        foreach (array_values($references) as $i => $columns) {
            foreach ($columns as $j => $reference) {
                [$table, $column] = explode('.', $reference, 2);

                $query->selectSub(
                    $query->getQuery()->newQuery()
                        ->from($table.' as lifecycle_ref')
                        ->selectRaw('count(*)')
                        ->whereColumn('lifecycle_ref.'.$column, $this->getQualifiedKeyName()),
                    'reference_'.$i.'_'.$j,
                );
            }
        }

        return $query;
    }

    /**
     * What the withReferenceCounts() sub-selects returned; null when the query did not run them.
     *
     * @return list<array{label: string, count: int}>|null
     */
    public function referenceCounts(): ?array
    {
        $references = static::lifecycleReferences();
        $attributes = $this->getAttributes();

        if ($references === null || ! array_key_exists('reference_0_0', $attributes)) {
            return null;
        }

        $counts = [];

        foreach (array_keys($references) as $i => $label) {
            $count = 0;

            foreach (array_keys($references[$label]) as $j) {
                $count += (int) $attributes['reference_'.$i.'_'.$j];
            }

            $counts[] = ['label' => $label, 'count' => $count];
        }

        return $counts;
    }

    /**
     * A fresh count, one query per column, for the delete decision. Trashed rows count.
     *
     * @return list<array{label: string, count: int}>
     */
    public function countReferences(): array
    {
        $counts = [];

        foreach (static::lifecycleReferences() ?? [] as $label => $columns) {
            $count = 0;

            foreach ($columns as $reference) {
                [$table, $column] = explode('.', $reference, 2);

                $count += $this->getConnection()->table($table)->where($column, $this->getKey())->count();
            }

            $counts[] = ['label' => $label, 'count' => $count];
        }

        return $counts;
    }

    /** @return array{deleted_at: string|null, references: list<array{label: string, count: int}>|null} */
    public function lifecycleAttributes(): array
    {
        return [
            'deleted_at' => $this->getAttribute($this->getDeletedAtColumn())?->toISOString(),
            'references' => $this->referenceCounts(),
        ];
    }
}
