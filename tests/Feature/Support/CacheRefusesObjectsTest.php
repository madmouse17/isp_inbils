<?php

namespace Tests\Feature\Support;

use Illuminate\Support\Facades\Cache;
use stdClass;
use Tests\TestCase;

/**
 * config/cache.php sets serializable_classes to false, and its array store (the one this suite runs on) serializes like
 * Redis and the database store. A cache entry that holds a model, a collection or a Carbon therefore fails in the tests
 * the way it fails in production, not only after a deploy.
 */
class CacheRefusesObjectsTest extends TestCase
{
    public function test_no_class_may_be_unserialized_from_the_cache(): void
    {
        $this->assertFalse(config('cache.serializable_classes'));

        Cache::put('guard:object', new stdClass(), 60);

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, Cache::get('guard:object'));
    }

    public function test_scalars_and_arrays_round_trip(): void
    {
        $rows = [['id' => 1, 'title' => 'a', 'ends_at' => null], ['id' => 2, 'title' => 'b', 'ends_at' => '2026-10-22 10:00:00']];

        Cache::put('guard:rows', $rows, 60);

        $this->assertSame($rows, Cache::get('guard:rows'));
    }
}
