<?php

namespace App\Services\Core;

use App\Models\Core\Location;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class LocationService
{
    public static function create(array $data): Location
    {
        return DB::transaction(function () use ($data) {
            $location = Location::query()->create($data);
            self::recomputePath($location);

            return $location->refresh();
        });
    }

    public static function update(Location $location, array $data): Location
    {
        return DB::transaction(function () use ($location, $data) {
            $pathChanged = array_key_exists('code', $data) || array_key_exists('parent_id', $data);

            if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null && self::hasCycle($location, (int) $data['parent_id'])) {
                throw ValidationException::withMessages(['parent_id' => 'Location cannot be moved below itself.']);
            }

            $location->update($data);

            if ($pathChanged) {
                self::recomputePath($location);
                self::recurseChildrenPath($location);
            }

            return $location->refresh();
        });
    }

    public static function move(Location $location, int $newParentId): Location
    {
        if (self::hasCycle($location, $newParentId)) {
            throw ValidationException::withMessages(['new_parent_id' => 'Location cannot be moved below itself.']);
        }

        return self::update($location, ['parent_id' => $newParentId]);
    }

    public static function delete(Location $location): bool
    {
        if ($location->children()->exists()) {
            throw ValidationException::withMessages(['location' => 'Location still has children.']);
        }

        if (self::hasActiveReference('network_assets', $location->id) || self::hasActiveReference('stocks', $location->id)) {
            throw ValidationException::withMessages(['location' => 'Location still has active references.']);
        }

        return (bool) $location->delete();
    }

    public static function tree(): Collection
    {
        $locations = Location::query()->orderBy('path')->get();

        return self::nest($locations);
    }

    public static function recomputePath(Location $location): string
    {
        $parentPath = $location->parent_id ? Location::query()->find($location->parent_id)?->path : null;
        $location->path = $parentPath ? $parentPath.' > '.$location->code : $location->code;
        $location->save();

        return $location->path;
    }

    public static function recurseChildrenPath(Location $location): void
    {
        $location->children()->orderBy('code')->get()->each(function (Location $child) {
            self::recomputePath($child);
            self::recurseChildrenPath($child);
        });
    }

    public static function hasCycle(Location $location, int $newParentId): bool
    {
        $parent = Location::query()->find($newParentId);

        while ($parent !== null) {
            if ($parent->id === $location->id) {
                return true;
            }

            $parent = $parent->parent;
        }

        return false;
    }

    private static function hasActiveReference(string $table, int $locationId): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'location_id')) {
            return false;
        }

        $query = DB::table($table)->where('location_id', $locationId);

        if (Schema::hasColumn($table, 'company_id') && CompanyService::currentId() !== null) {
            $query->where('company_id', CompanyService::currentId());
        }

        if (Schema::hasColumn($table, 'is_active')) {
            $query->where('is_active', true);
        }

        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->exists();
    }

    private static function nest(EloquentCollection $locations, ?int $parentId = null): Collection
    {
        return $locations
            ->where('parent_id', $parentId)
            ->values()
            ->map(function (Location $location) use ($locations) {
                $location->setRelation('children', self::nest($locations, $location->id));

                return $location;
            });
    }
}
