<?php

namespace App\Services\Core;

use App\Models\Core\Location;
use App\Support\RecordNotFound;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocationService
{
    /**
     * The parent types a type may sit under. A site sits under a rack, a POP or an area: a small ISP has no racks.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_PARENT_TYPES = [
        'area' => ['region'],
        'pop' => ['area'],
        'rack' => ['pop'],
        'site' => ['rack', 'pop', 'area'],
    ];

    /**
     * The type words messages use, the same as `LOCATION_TYPE` in `resources/js/lib/statuses/location.ts`.
     *
     * @var array<string, string>
     */
    private const TYPE_LABELS = [
        'region' => 'Wilayah',
        'area' => 'Area',
        'pop' => 'POP',
        'rack' => 'Rak',
        'site' => 'Site',
    ];

    public static function create(array $data): Location
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Company context is required.');

        return DB::transaction(function () use ($data, $companyId) {
            $data['company_id'] = $companyId;
            unset($data['path']);

            $effectiveType = $data['type'] ?? null;
            self::assertValidType($effectiveType);
            $requestedParentId = $data['parent_id'] ?? null;

            if ($effectiveType === 'region') {
                if (! blank($requestedParentId)) {
                    throw ValidationException::withMessages(['parent_id' => 'Lokasi Wilayah tidak boleh punya induk.']);
                }

                $data['parent_id'] = null;
            } else {
                if (blank($requestedParentId)) {
                    throw ValidationException::withMessages(['parent_id' => 'Induk lokasi wajib diisi untuk lokasi selain Wilayah.']);
                }

                $data['parent_id'] = self::assertParentValidAndWithinCompany(
                    (int) $requestedParentId,
                    $companyId,
                    $effectiveType
                )->id;
            }

            $location = Location::query()->create($data);
            self::recomputePath($location);

            return $location->refresh();
        });
    }

    public static function update(Location $location, array $data): Location
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Company context is required.');

        return DB::transaction(function () use ($location, $data, $companyId) {
            $location = Location::withoutCompany()
                ->whereKey($location->id)
                ->lockForUpdate()
                ->firstOrFail();

            self::assertCompany($location, $companyId);

            unset($data['company_id'], $data['path']);

            $effectiveType = $data['type'] ?? $location->type;
            self::assertValidType($effectiveType);

            if ($effectiveType !== $location->type) {
                self::assertChildrenAcceptParentType($location, $effectiveType);
            }

            $requestedParentId = $data['parent_id'] ?? null;

            if ($effectiveType === 'region') {
                if (! blank($requestedParentId)) {
                    throw ValidationException::withMessages(['parent_id' => 'Lokasi Wilayah tidak boleh punya induk.']);
                }

                $data['parent_id'] = null;
            } else {
                $newParentId = blank($requestedParentId) ? $location->parent_id : $requestedParentId;

                if (blank($newParentId)) {
                    throw ValidationException::withMessages(['parent_id' => 'Induk lokasi wajib diisi untuk lokasi selain Wilayah.']);
                }

                $newParentId = (int) $newParentId;
                self::assertParentValidAndWithinCompany($newParentId, $companyId, $effectiveType);

                if (self::hasCycle($location, $newParentId)) {
                    throw ValidationException::withMessages(['parent_id' => 'Lokasi tidak dapat dipindahkan ke bawah dirinya sendiri.']);
                }

                $data['parent_id'] = $newParentId;
            }

            $pathChanged = array_key_exists('code', $data) || array_key_exists('parent_id', $data);

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
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Company context is required.');

        return DB::transaction(function () use ($location, $newParentId, $companyId) {
            $location = Location::withoutCompany()
                ->whereKey($location->id)
                ->lockForUpdate()
                ->firstOrFail();

            self::assertCompany($location, $companyId);

            if ($location->type === 'region') {
                throw ValidationException::withMessages(['new_parent_id' => 'Lokasi Wilayah tidak boleh punya induk.']);
            }

            self::assertParentValidAndWithinCompany($newParentId, $companyId, $location->type, 'new_parent_id');

            if (self::hasCycle($location, $newParentId)) {
                throw ValidationException::withMessages(['new_parent_id' => 'Lokasi tidak dapat dipindahkan ke bawah dirinya sendiri.']);
            }

            return self::update($location, ['parent_id' => $newParentId]);
        });
    }

    public static function tree(): Collection
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Company context is required.');

        $locations = Location::query()
            ->where('company_id', $companyId)
            ->orderBy('path')
            ->get();

        return self::nest($locations);
    }

    public static function recomputePath(Location $location): string
    {
        $parentPath = $location->parent_id
            ? Location::withoutCompany()
                ->where('company_id', $location->company_id)
                ->find($location->parent_id)?->path
            : null;

        $location->path = $parentPath ? $parentPath.' > '.$location->code : $location->code;
        $location->saveQuietly();

        return $location->path;
    }

    public static function recurseChildrenPath(Location $location): void
    {
        Location::withoutCompany()
            ->where('parent_id', $location->id)
            ->where('company_id', $location->company_id)
            ->orderBy('code')
            ->get()
            ->each(function (Location $child): void {
                self::recomputePath($child);
                self::recurseChildrenPath($child);
            });
    }

    public static function hasCycle(Location $location, int $newParentId): bool
    {
        $parent = Location::withoutCompany()
            ->where('company_id', $location->company_id)
            ->find($newParentId);

        while ($parent !== null) {
            if ($parent->id === $location->id) {
                return true;
            }

            $parent = $parent->parent_id
                ? Location::withoutCompany()
                    ->where('company_id', $location->company_id)
                    ->find($parent->parent_id)
                : null;
        }

        return false;
    }

    private static function assertCompany(Location $location, int $expectedCompanyId): void
    {
        $companyId = $location->getAttribute('company_id');

        RecordNotFound::abortUnless($companyId !== null && (int) $companyId === (int) $expectedCompanyId);
    }

    private static function assertParentValidAndWithinCompany(
        int $parentId,
        int $expectedCompanyId,
        string $childType,
        string $attribute = 'parent_id'
    ): Location {
        $parent = Location::withoutCompany()
            ->whereKey($parentId)
            ->where('company_id', $expectedCompanyId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();

        abort_unless($parent instanceof Location, 404, 'Induk lokasi tidak ditemukan atau tidak valid.');

        $allowedParentTypes = self::ALLOWED_PARENT_TYPES[$childType] ?? null;
        if ($allowedParentTypes !== null && ! in_array($parent->type, $allowedParentTypes, true)) {
            throw ValidationException::withMessages([
                $attribute => sprintf(
                    'Lokasi %s harus punya induk %s.',
                    self::TYPE_LABELS[$childType] ?? $childType,
                    self::typeLabels($allowedParentTypes)
                ),
            ]);
        }

        return $parent;
    }

    private static function assertValidType(string $type): void
    {
        if ($type !== 'region' && ! array_key_exists($type, self::ALLOWED_PARENT_TYPES)) {
            throw ValidationException::withMessages(['type' => 'Tipe lokasi tidak valid.']);
        }
    }

    /** @param  list<string>  $types */
    private static function typeLabels(array $types): string
    {
        return Arr::join(array_map(fn (string $type): string => self::TYPE_LABELS[$type] ?? $type, $types), ', ', ' atau ');
    }

    private static function assertChildrenAcceptParentType(Location $location, string $newParentType): void
    {
        $children = Location::withoutCompany()
            ->where('company_id', $location->company_id)
            ->where('parent_id', $location->id)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->get(['id', 'type']);

        foreach ($children as $child) {
            $allowedParentTypes = self::ALLOWED_PARENT_TYPES[$child->type] ?? null;

            if ($allowedParentTypes === null || ! in_array($newParentType, $allowedParentTypes, true)) {
                throw ValidationException::withMessages([
                    'type' => sprintf(
                        'Lokasi %s tidak dapat punya induk %s.',
                        self::TYPE_LABELS[$child->type] ?? $child->type,
                        self::TYPE_LABELS[$newParentType] ?? $newParentType
                    ),
                ]);
            }
        }
    }

    private static function nest(EloquentCollection $locations, ?int $parentId = null): Collection
    {
        return $locations
            ->where('parent_id', $parentId)
            ->values()
            ->map(function (mixed $location) use ($locations): Location {
                /** @var Location $loc */
                $loc = $location;
                $loc->setRelation('children', self::nest($locations, $loc->id));

                return $loc;
            });
    }
}
