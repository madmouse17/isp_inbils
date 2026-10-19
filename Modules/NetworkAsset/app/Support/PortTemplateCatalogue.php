<?php

namespace Modules\NetworkAsset\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\NetworkAsset\Models\PortTemplate;
use RuntimeException;

/**
 * The shipped catalogue of device faces, database/data/port_templates.json, and its sync into port_templates. Models
 * are data: a new unit is one more row in the file plus a migration that calls sync(); a unit nobody catalogued is
 * drawn from the custom layout without a deploy.
 */
final class PortTemplateCatalogue
{
    private const FILE = 'database/data/port_templates.json';

    /** @return list<string> the keys of the shipped rows */
    public static function keys(): array
    {
        return array_column(self::read(), 'key');
    }

    /**
     * Inserts or updates every shipped row and never deletes, so a row an operator added by hand survives and running
     * it twice leaves the same rows. A row that breaks the layout rules fails the migration loudly.
     *
     * @return int the number of shipped rows
     */
    public static function sync(): int
    {
        $now = now();
        $rows = array_map(fn (array $row): array => [...self::checked($row), 'created_at' => $now, 'updated_at' => $now], self::read());

        DB::table('port_templates')->upsert($rows, ['key'], ['vendor', 'model', 'asset_type', 'height_u', 'layout', 'source_url', 'updated_at']);

        return count($rows);
    }

    /** @return list<array<string, mixed>> */
    private static function read(): array
    {
        return json_decode((string) file_get_contents(module_path('NetworkAsset', self::FILE)), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed> the row as port_templates stores it
     */
    private static function checked(array $row): array
    {
        try {
            Validator::make($row, [
                'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
                'vendor' => ['required', 'string', 'max:64'],
                'model' => ['required', 'string', 'max:128'],
                'asset_type' => ['required', Rule::in(PortTemplate::ASSET_TYPES)],
                'height_u' => ['nullable', 'integer', 'between:1,52'],
                'source_url' => ['nullable', 'string', 'max:255'],
            ])->validate();

            $layout = PanelLayout::validate($row['layout'] ?? null, $row['asset_type'], catalogue: true);
        } catch (ValidationException $e) {
            throw new RuntimeException('port_templates.json, row "'.($row['key'] ?? '?').'": '.implode(' ', Arr::flatten($e->errors())), previous: $e);
        }

        return [
            'key' => $row['key'],
            'vendor' => $row['vendor'],
            'model' => $row['model'],
            'asset_type' => $row['asset_type'],
            'height_u' => $row['height_u'] ?? null,
            'layout' => json_encode($layout, JSON_THROW_ON_ERROR),
            'source_url' => $row['source_url'] ?? null,
        ];
    }
}
