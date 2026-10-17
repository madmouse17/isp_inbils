<?php

namespace App\Services\Core;

use App\Models\Core\OrganizationUnit;
use App\Support\RecordNotFound;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationService
{
    public static function create(array $data): OrganizationUnit
    {
        return DB::transaction(function () use ($data) {
            if (array_key_exists('parent_id', $data) && (int) $data['parent_id'] === 0) {
                $data['parent_id'] = null;
            }

            $unit = OrganizationUnit::create($data);
            self::assertCompany($unit);
            self::assertParentWithinCompany($unit, $unit->parent_id);
            self::recomputePath($unit);
            AuditService::log('organization_unit', 'created', ['code' => $unit->code], $unit);

            return $unit->refresh();
        });
    }

    public static function update(OrganizationUnit $unit, array $data): OrganizationUnit
    {
        return DB::transaction(function () use ($unit, $data) {
            $unit = OrganizationUnit::withoutCompany()->whereKey($unit->id)->lockForUpdate()->firstOrFail();
            self::assertCompany($unit);

            if (array_key_exists('parent_id', $data) && (int) $data['parent_id'] === 0) {
                $data['parent_id'] = null;
            }

            if (array_key_exists('parent_id', $data)) {
                self::assertParentWithinCompany($unit, $data['parent_id']);
            }

            if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null && self::hasCycle($unit, (int) $data['parent_id'])) {
                throw ValidationException::withMessages(['parent_id' => 'Unit tidak bisa dipindahkan ke bawah dirinya sendiri atau sub-unitnya.']);
            }

            $pathChanged = array_key_exists('code', $data) || array_key_exists('parent_id', $data);
            $unit->update($data);

            if ($pathChanged) {
                self::recomputePath($unit);
                self::recurseChildrenPath($unit);
            }

            AuditService::log('organization_unit', 'updated', ['code' => $unit->code], $unit);

            return $unit->refresh();
        });
    }

    public static function move(OrganizationUnit $unit, ?int $newParentId): OrganizationUnit
    {
        return DB::transaction(function () use ($unit, $newParentId) {
            $unit = OrganizationUnit::withoutCompany()->whereKey($unit->id)->lockForUpdate()->firstOrFail();
            self::assertCompany($unit);

            $newParentId = $newParentId === 0 ? null : $newParentId;
            self::assertParentWithinCompany($unit, $newParentId);

            if ($newParentId !== null && self::hasCycle($unit, $newParentId)) {
                throw ValidationException::withMessages(['parent_id' => 'Unit tidak bisa dipindahkan ke bawah dirinya sendiri atau sub-unitnya.']);
            }

            return self::update($unit, ['parent_id' => $newParentId]);
        });
    }

    public static function delete(OrganizationUnit $unit): bool
    {
        if ($unit->children()->exists()) {
            throw ValidationException::withMessages(['organization' => 'Unit masih punya sub-unit. Pindahkan atau hapus sub-unitnya dulu.']);
        }

        if ($unit->employees()->exists()) {
            throw ValidationException::withMessages(['organization' => 'Unit masih punya karyawan. Pindahkan karyawannya ke unit lain dulu.']);
        }

        return DB::transaction(function () use ($unit) {
            AuditService::log('organization_unit', 'deleted', ['code' => $unit->code]);
            $unit->delete();

            return true;
        });
    }

    public static function recomputePath(OrganizationUnit $unit): string
    {
        $parentPath = $unit->parent_id
            ? OrganizationUnit::withoutCompany()->where('company_id', $unit->company_id)->find($unit->parent_id)?->path
            : null;
        $unit->path = $parentPath ? $parentPath.' > '.$unit->code : $unit->code;
        $unit->save();

        return $unit->path;
    }

    public static function recurseChildrenPath(OrganizationUnit $unit): void
    {
        /** @var Collection<int, OrganizationUnit> $children */
        $children = $unit->children()->orderBy('code')->get();
        $children->each(function (OrganizationUnit $child): void {
            self::recomputePath($child);
            self::recurseChildrenPath($child);
        });
    }

    public static function hasCycle(OrganizationUnit $unit, int $newParentId): bool
    {
        $parent = OrganizationUnit::withoutCompany()
            ->where('company_id', $unit->company_id)
            ->find($newParentId);

        while ($parent !== null) {
            if ($parent->id === $unit->id) {
                return true;
            }

            $parent = OrganizationUnit::withoutCompany()
                ->where('company_id', $unit->company_id)
                ->find($parent->parent_id);
        }

        return false;
    }

    private static function assertParentWithinCompany(OrganizationUnit $unit, mixed $parentId): void
    {
        if ($parentId === null || $parentId === '' || $parentId === 0) {
            return;
        }

        $parent = OrganizationUnit::withoutCompany()
            ->where('company_id', $unit->company_id)
            ->lockForUpdate()
            ->find($parentId);

        RecordNotFound::abortUnless($parent !== null && (int) $parent->company_id === (int) $unit->company_id);
    }

    private static function assertCompany(OrganizationUnit $unit): void
    {
        RecordNotFound::abortUnless(CompanyService::currentId() !== null && (int) $unit->company_id === (int) CompanyService::currentId());
    }
}
