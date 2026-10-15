<?php

namespace App\Support;

/**
 * Permission pairs no non-admin role may hold together, declared once for the role-save
 * guard, the seeded-persona tests and docs/SECURITY.md §2.2. Admin is the break-glass exception.
 */
final class SodConflicts
{
    /** @var array<string, array{control: list<string>, execution: list<string>}> */
    public const SETS = [
        'persetujuan permintaan barang vs pelaksanaan gudang' => [
            'control' => ['inventory.request.approve', 'inventory.request.reject'],
            'execution' => [
                'inventory.request.prepare', 'inventory.request.issue',
                'inventory.stock.receive', 'inventory.stock.issue', 'inventory.stock.transfer',
            ],
        ],
        'persetujuan penyesuaian stok vs operasi stok' => [
            'control' => ['inventory.stock.adjust.approve'],
            'execution' => [
                'inventory.stock.adjust.request',
                'inventory.stock.receive', 'inventory.stock.issue', 'inventory.stock.transfer',
            ],
        ],
    ];

    /** Break-glass permissions only an administrator may grant. */
    public const ADMIN_ONLY = ['inventory.stock.adjust'];

    /**
     * @param  list<string>  $permissions
     * @return list<string> one message per violated rule; empty when the set is clean
     */
    public static function violations(array $permissions): array
    {
        $violations = [];

        foreach (self::SETS as $label => $set) {
            $control = array_values(array_intersect($set['control'], $permissions));
            $execution = array_values(array_intersect($set['execution'], $permissions));

            if ($control !== [] && $execution !== []) {
                $violations[] = sprintf('Pemisahan tugas (%s): %s tidak boleh digabung dengan %s.', $label, implode(', ', $control), implode(', ', $execution));
            }
        }

        foreach (array_intersect(self::ADMIN_ONLY, $permissions) as $permission) {
            $violations[] = "Hanya administrator yang boleh memberikan hak akses darurat {$permission}.";
        }

        return $violations;
    }
}
