<?php

namespace App\Services\Core;

use App\Models\Core\OrganizationUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationService
{
    public static function create(array $data): OrganizationUnit
    {
        return DB::transaction(function () use ($data) {
            $unit = OrganizationUnit::create($data);
            self::recomputePath($unit);
            AuditService::log('organization_unit', 'created', ['code' => $unit->code], $unit);
            return $unit->refresh();
        });
    }

    public static function update(OrganizationUnit $unit, array $data): OrganizationUnit
    {
        return DB::transaction(function () use ($unit, $data) {
            $pathChanged = array_key_exists('code', $data) || array_key_exists('parent_id', $data);
            if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null && self::hasCycle($unit, (int) $data['parent_id'])) {
                throw ValidationException::withMessages(['parent_id' => 'Cannot move unit below itself.']);
            }
            $unit->update($data);
            if ($pathChanged) {
                self::recomputePath($unit);
                self::recurseChildrenPath($unit);
            }
            AuditService::log('organization_unit', 'updated', ['code' => $unit->code], $unit);
            return $unit->refresh();
        });
    }

    public static function move(OrganizationUnit $unit, int $newParentId): OrganizationUnit
    {
        if (self::hasCycle($unit, $newParentId)) {
            throw ValidationException::withMessages(['parent_id' => 'Cannot move unit below itself.']);
        }
        return self::update($unit, ['parent_id' => $newParentId]);
    }

    public static function delete(OrganizationUnit $unit): bool
    {
        if ($unit->children()->exists()) {
            throw ValidationException::withMessages(['organization' => 'Unit still has children.']);
        }
        return DB::transaction(function () use ($unit) {
            AuditService::log('organization_unit', 'deleted', ['code' => $unit->code]);
            return $unit->delete();
        });
    }

    public static function recomputePath(OrganizationUnit $unit): string
    {
        $parentPath = $unit->parent_id ? OrganizationUnit::query()->find($unit->parent_id)?->path : null;
        $unit->path = $parentPath ? $parentPath . ' > ' . $unit->code : $unit->code;
        $unit->save();
        return $unit->path;
    }

    public static function recurseChildrenPath(OrganizationUnit $unit): void
    {
        $unit->children()->orderBy('code')->get()->each(function (OrganizationUnit $child) {
            self::recomputePath($child);
            self::recurseChildrenPath($child);
        });
    }

    public static function hasCycle(OrganizationUnit $unit, int $newParentId): bool
    {
        $parent = OrganizationUnit::query()->find($newParentId);
        while ($parent !== null) {
            if ($parent->id === $unit->id) {
                return true;
            }
            $parent = $parent->parent;
        }
        return false;
    }
}
