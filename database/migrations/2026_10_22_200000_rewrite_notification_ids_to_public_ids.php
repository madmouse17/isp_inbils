<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opaque ids (spec .scratch/opaque-ids D6): a bell row keeps the record it is about in `data` (`ticket_id`,
 * `work_order_id`, `subject.id`, ...) and the bell and the apps read it back. Rows written before the public ids hold
 * the integer id; this rewrites them to the record's `public_id`, so the table holds no integer reference any more.
 * A record that no longer exists leaves null. No stored notification carries a URL (the bell computes its link when
 * it renders), so there is no link to rewrite. Running it again changes nothing.
 */
return new class() extends Migration
{
    private const CHUNK = 500;

    /** @var array<string, string> type of record => table */
    private const TABLES = [
        'ticket' => 'tickets',
        'work_order' => 'work_orders',
        'invoice' => 'invoices',
        'inventory_request' => 'inventory_requests',
        'material_return' => 'material_return_cases',
        'announcement' => 'announcements',
        'user' => 'users',
    ];

    /** @var array<string, string> key in `data` => type of record */
    private const KEYS = [
        'ticket_id' => 'ticket',
        'work_order_id' => 'work_order',
        'invoice_id' => 'invoice',
        'request_id' => 'inventory_request',
        'inventory_request_id' => 'inventory_request',
        'case_id' => 'material_return',
        'announcement_id' => 'announcement',
        'user_id' => 'user',
    ];

    public function up(): void
    {
        $this->rewrite(
            fn (mixed $value): bool => is_int($value) || (is_string($value) && ctype_digit($value)),
            fn (string $table, array $ids): array => DB::table($table)->whereIn('id', $ids)->pluck('public_id', 'id')->all(),
            true,
        );
    }

    /** A record deleted since cannot be mapped back: its public id stays. */
    public function down(): void
    {
        $this->rewrite(
            fn (mixed $value): bool => is_string($value) && Str::isUlid($value),
            fn (string $table, array $ids): array => DB::table($table)->whereIn('public_id', $ids)->pluck('id', 'public_id')->all(),
            false,
        );
    }

    /**
     * @param  Closure(mixed): bool  $isStored  whether a value is in the form this direction replaces
     * @param  Closure(string, list<string>): array<string, int|string>  $lookup  stored value => its replacement, per table
     * @param  bool  $dropUnmapped  whether a value without a record becomes null (true) or stays as it is (false)
     */
    private function rewrite(Closure $isStored, Closure $lookup, bool $dropUnmapped): void
    {
        DB::table('notifications')->select(['id', 'data'])->chunkById(self::CHUNK, function ($rows) use ($isStored, $lookup, $dropUnmapped): void {
            $decoded = [];
            $wanted = [];

            foreach ($rows as $row) {
                $data = json_decode((string) $row->data, true);

                if (! is_array($data)) {
                    continue;
                }

                $decoded[$row->id] = $data;

                foreach ($this->slots($data) as [$path, $type]) {
                    $value = data_get($data, $path);

                    if ($isStored($value)) {
                        $wanted[$type][(string) $value] = true;
                    }
                }
            }

            $found = [];

            foreach ($wanted as $type => $values) {
                $found[$type] = $lookup(self::TABLES[$type], array_map('strval', array_keys($values)));
            }

            DB::transaction(function () use ($decoded, $found, $isStored, $dropUnmapped): void {
                foreach ($decoded as $id => $data) {
                    $changed = false;

                    foreach ($this->slots($data) as [$path, $type]) {
                        $value = data_get($data, $path);

                        if (! $isStored($value)) {
                            continue;
                        }

                        if (array_key_exists((string) $value, $found[$type] ?? [])) {
                            data_set($data, $path, $found[$type][(string) $value]);
                        } elseif ($dropUnmapped) {
                            data_set($data, $path, null);
                        } else {
                            continue;
                        }

                        $changed = true;
                    }

                    if ($changed) {
                        DB::table('notifications')->where('id', $id)->update(['data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                    }
                }
            });
        }, 'id');
    }

    /**
     * Where a stored row names a record: the dotted path in `data` and the type of record.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{0: string, 1: string}>
     */
    private function slots(array $data): array
    {
        $slots = [];

        foreach (self::KEYS as $key => $type) {
            if (array_key_exists($key, $data)) {
                $slots[] = [$key, $type];
            }
        }

        $subjectType = data_get($data, 'subject.type');

        if (is_string($subjectType) && isset(self::TABLES[$subjectType]) && data_get($data, 'subject.id') !== null) {
            $slots[] = ['subject.id', $subjectType];
        }

        return $slots;
    }
};
