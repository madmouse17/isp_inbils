<?php

namespace Modules\NetworkAsset\Support;

use Generator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;
use Modules\NetworkAsset\Models\OdnSplitter;

/**
 * The face of a device as data (spec .scratch/hardware-panel-ui, design §3.4): chassis rows from top to bottom, each a
 * list of blocks from left to right. A block is a run of ports of one kind ({group, kind, count, ...}) or a fixture
 * without a port ({fixture}). The same array is stored in port_templates.layout and network_assets.port_layout, and
 * is sent to the client, so one rule set checks it for the apply request and for the catalogue sync.
 *
 * Ports of a block are numbered from `from` (default 1) inside their group, so one group can span blocks. Their
 * vendor name comes from the block's `name` pattern: {port} = number - 1 + first, {slot} = slot. A block with
 * `role: pon` is backed by odn_ports (OLT only), one with `role: splitter` by a splitter (ODP catalogue rows only);
 * every other block has one asset_ports row per port.
 */
final class PanelLayout
{
    /** The asset types that have a panel. */
    public const ASSET_TYPES = ['router', 'switch', 'olt', 'onu_ont', 'odc', 'odp', 'rack'];

    /**
     * shortcut: the panel never virtualises, so a layout stops here; raise the cap only together with row
     * virtualisation in the renderer (panelGeometry.helpers.ts).
     */
    public const MAX_PORTS = 256;

    public const KINDS = ['rj45', 'sfp', 'sc', 'rj11'];

    public const FIXTURES = ['power', 'console', 'usb', 'wlan', 'reset'];

    private const DEFAULT_NAMES = ['rj45' => 'ether{port}', 'sfp' => 'sfp{port}', 'sc' => 'sc{port}', 'rj11' => 'tel{port}'];

    private const PORT_KEYS = ['group', 'kind', 'count', 'from', 'stack', 'order', 'name', 'first', 'slot', 'title', 'speed', 'role'];

    private const INTEGER_KEYS = ['count', 'from', 'stack', 'first', 'slot'];

    /**
     * Laravel rules for the structure of a layout found under $field ("layout"); the caller adds the rules of $field
     * itself. What depends on several blocks is checked by problems().
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $field, string $assetType): array
    {
        $block = "{$field}.rows.*.*";
        $roles = self::roles($assetType);

        return [
            "{$field}.rows" => ["required_with:{$field}", 'array', 'min:1', 'max:8'],
            "{$field}.rows.*" => ['required', 'array', 'min:1', 'max:16'],
            $block => ['required', 'array'],
            "{$block}.group" => ["required_without:{$block}.fixture", "prohibits:{$block}.fixture", 'string', 'regex:/^[a-z][a-z0-9]{0,15}$/'],
            "{$block}.kind" => ["required_with:{$block}.group", Rule::in(self::KINDS)],
            "{$block}.count" => ["required_with:{$block}.group", 'integer', 'between:1,64'],
            "{$block}.from" => ['nullable', 'integer', 'between:1,256'],
            "{$block}.stack" => ['nullable', Rule::in([1, 2])],
            "{$block}.order" => ['nullable', Rule::in(['row', 'column'])],
            "{$block}.name" => ['nullable', 'string', 'max:32'],
            "{$block}.first" => ['nullable', Rule::in([0, 1])],
            "{$block}.slot" => ['nullable', 'integer', 'between:0,99'],
            "{$block}.title" => ['nullable', 'string', 'max:40'],
            "{$block}.speed" => ['nullable', 'string', 'max:16'],
            "{$block}.role" => ['nullable', $roles === [] ? 'prohibited' : Rule::in($roles)],
            "{$block}.fixture" => ["required_without:{$block}.group", Rule::in(self::FIXTURES)],
        ];
    }

    /**
     * What a layout that passed rules() can still get wrong, in Indonesian, one sentence each; [] when it is valid.
     * $catalogue is true for a row of the shipped catalogue, the only place a splitter block may come from.
     *
     * @param  array<string, mixed>  $layout  canonical (see validate())
     * @return list<string>
     */
    public static function problems(array $layout, string $assetType, bool $catalogue = false): array
    {
        if ($assetType === 'rack') {
            return ['Rak tidak punya port; atur ukurannya saja.'];
        }

        $problems = [];

        if (! $catalogue && in_array($assetType, ['odc', 'odp'], true)) {
            $problems[] = 'ODC dan ODP tidak memakai tata letak sendiri: port-nya berasal dari splitter.';
        }

        $total = self::portCount($layout);

        if ($total < 1) {
            $problems[] = 'Tata letak harus punya sedikitnya satu port.';
        }

        if ($total > self::MAX_PORTS) {
            $problems[] = 'Satu perangkat paling banyak '.self::MAX_PORTS." port; tata letak ini {$total}.";
        }

        $groups = [];

        foreach (self::portBlocks($layout) as $block) {
            $key = $block['group'];
            $role = $block['role'] ?? 'port';
            $seen = $groups[$key]['numbers'] ?? [];

            if (isset($groups[$key]) && $groups[$key]['kind'] !== $block['kind']) {
                $problems[] = "Grup {$key} memakai lebih dari satu jenis port.";
            }

            if (isset($groups[$key]) && $groups[$key]['role'] !== $role) {
                $problems[] = "Grup {$key} mencampur port PON atau splitter dengan port biasa.";
            }

            foreach (self::numbers($block) as $number) {
                if (isset($seen[$number])) {
                    $problems[] = "Port {$key} nomor {$number} muncul lebih dari sekali.";

                    break;
                }

                $seen[$number] = true;
            }

            $groups[$key] = [
                'kind' => $groups[$key]['kind'] ?? $block['kind'],
                'role' => $groups[$key]['role'] ?? $role,
                'ports' => ($groups[$key]['ports'] ?? 0) + $block['count'],
                'numbers' => $seen,
            ];

            if ($role === 'pon' && $block['kind'] !== 'sfp') {
                $problems[] = "Port PON di grup {$key} harus berjenis sfp.";
            }

            if ($role === 'splitter' && ! $catalogue) {
                $problems[] = 'Splitter hanya datang dari model katalog.';
            }

            if ($role === 'splitter' && ! in_array($block['count'], OdnSplitter::OUTPUTS, true)) {
                $problems[] = "Splitter 1:{$block['count']} tidak didukung.";
            }

            if (str_contains($block['name'] ?? '', '{slot}') && ! isset($block['slot'])) {
                $problems[] = "Nama port di grup {$key} memakai {slot}, tetapi blok itu tidak punya slot.";
            }
        }

        foreach (self::portBlocks($layout) as $block) {
            if ($groups[$block['group']]['ports'] > 1 && isset($block['name']) && ! str_contains($block['name'], '{port}')) {
                $problems[] = "Nama port di grup {$block['group']} harus memuat {port}, karena grupnya punya banyak port.";
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * The rules and problems() for a layout nobody has checked yet (a seeder, a fixture, the catalogue): the
     * canonical layout, or a ValidationException on `layout`.
     *
     * @return array{rows: list<list<array<string, mixed>>>}
     */
    public static function validate(mixed $layout, string $assetType, bool $catalogue = false): array
    {
        $validator = Validator::make(['layout' => $layout], ['layout' => ['required', 'array'], ...self::rules('layout', $assetType)]);
        $validator->after(fn (ValidatorInstance $checked) => self::checkAfter($checked, $assetType, $catalogue));
        $validator->validate();

        return self::canonical($layout);
    }

    /** Adds problems() under `layout` once the structure rules passed; for a FormRequest::after() or a validator. */
    public static function checkAfter(ValidatorInstance $validator, string $assetType, bool $catalogue = false): void
    {
        $layout = $validator->getData()['layout'] ?? null;

        if (! is_array($layout) || $validator->errors()->hasAny(['layout', 'layout.*'])) {
            return;
        }

        foreach (self::problems(self::canonical($layout), $assetType, $catalogue) as $problem) {
            $validator->errors()->add('layout', $problem);
        }
    }

    /**
     * One entry per asset_ports row the layout calls for, in reading order. PON and splitter blocks are not here:
     * their ports are odn_ports rows.
     *
     * @param  array<string, mixed>  $layout
     * @return list<array{group_key: string, number: int, kind: string, name: string}>
     */
    public static function assetPortRows(array $layout): array
    {
        $rows = [];

        foreach (self::portBlocks($layout) as $block) {
            if (isset($block['role'])) {
                continue;
            }

            foreach (self::numbers($block) as $number) {
                $rows[] = ['group_key' => $block['group'], 'number' => $number, 'kind' => $block['kind'], 'name' => self::portName($block, $number)];
            }
        }

        return $rows;
    }

    /**
     * The PON ports of the model in reading order, keyed by their 1-based ordinal, which is the odn_ports.number the
     * ODN register gave the port.
     *
     * @param  array<string, mixed>  $layout
     * @return array<int, array{group: string, number: int, name: string}>
     */
    public static function ponSlots(array $layout): array
    {
        $slots = [];
        $ordinal = 0;

        foreach (self::portBlocks($layout) as $block) {
            if (($block['role'] ?? null) !== 'pon') {
                continue;
            }

            foreach (self::numbers($block) as $number) {
                $slots[++$ordinal] = ['group' => $block['group'], 'number' => $number, 'name' => self::portName($block, $number)];
            }
        }

        return $slots;
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return list<array<string, mixed>> the blocks that stand for a splitter
     */
    public static function splitterBlocks(array $layout): array
    {
        return array_values(array_filter(
            iterator_to_array(self::portBlocks($layout), false),
            static fn (array $block): bool => ($block['role'] ?? null) === 'splitter',
        ));
    }

    /** The vendor name of port $number of a block: its `name` pattern, or the default of its kind (PON ports: "PON 3"). */
    public static function portName(array $block, int $number): string
    {
        $pattern = $block['name'] ?? (($block['role'] ?? null) === 'pon' ? 'PON {port}' : self::DEFAULT_NAMES[$block['kind']]);

        return strtr($pattern, ['{port}' => (string) ($number - 1 + ($block['first'] ?? 1)), '{slot}' => (string) ($block['slot'] ?? '')]);
    }

    /**
     * Every port the layout draws, PON ports and splitter outputs included.
     *
     * @param  array<string, mixed>  $layout
     */
    public static function portCount(array $layout): int
    {
        $count = 0;

        foreach (self::portBlocks($layout) as $block) {
            $count += $block['count'];
        }

        return $count;
    }

    /** @return list<string> the `role` values a block of this asset type may carry */
    private static function roles(string $assetType): array
    {
        return match ($assetType) {
            'olt' => ['pon'],
            'odp' => ['splitter'],
            default => [],
        };
    }

    /**
     * Only the keys a block of its kind has, nulls dropped, numbers as integers, rows and blocks in reading order
     * (a validator hands the keys back in the order its rules ran, not the order of the list).
     *
     * @return array{rows: list<list<array<string, mixed>>>}
     */
    private static function canonical(mixed $layout): array
    {
        $rows = (array) ($layout['rows'] ?? []);
        ksort($rows);

        return ['rows' => array_values(array_map(static function (array $row): array {
            ksort($row);

            return array_values(array_map(self::block(...), $row));
        }, $rows))];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function block(array $block): array
    {
        $block = array_filter($block, static fn (mixed $value): bool => $value !== null);

        if (isset($block['fixture'])) {
            return array_intersect_key($block, ['fixture' => true, 'title' => true]);
        }

        $block = array_intersect_key($block, array_flip(self::PORT_KEYS));

        foreach (self::INTEGER_KEYS as $key) {
            if (isset($block[$key])) {
                $block[$key] = (int) $block[$key];
            }
        }

        return $block;
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return Generator<int, array<string, mixed>>
     */
    private static function portBlocks(array $layout): Generator
    {
        foreach ($layout['rows'] ?? [] as $row) {
            foreach ($row as $block) {
                if (isset($block['group'])) {
                    yield $block;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<int> the numbers of the block's ports inside its group
     */
    private static function numbers(array $block): array
    {
        $from = $block['from'] ?? 1;

        return range($from, $from + $block['count'] - 1);
    }
}
