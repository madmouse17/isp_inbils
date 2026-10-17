<?php

namespace Tests\Feature\PublicId;

use App\Support\PublicId;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Ulid;
use Tests\TestCase;

/** The format of `public_id`, and what an id must not give away: the id of the record created next to it. */
class PublicIdTest extends TestCase
{
    private const ULID = '/^[0-7][0-9a-hjkmnp-tv-z]{25}$/';

    public function test_a_new_id_is_a_lowercase_ulid_stamped_with_the_current_time(): void
    {
        $id = PublicId::new();

        $this->assertMatchesRegularExpression(self::ULID, $id);
        $this->assertTrue(Str::isUlid($id));
        $this->assertEqualsWithDelta(microtime(true), (float) Ulid::fromString($id)->getDateTime()->format('U.v'), 2.0);
    }

    public function test_ids_made_in_the_same_millisecond_do_not_count_up(): void
    {
        $ids = [];

        for ($made = 0; $made < 300; $made++) {
            $ids[] = PublicId::new();
        }

        $this->assertCount(300, array_unique($ids));

        // Ten characters of timestamp, then 16 of randomness: a generator that continues the previous id of the same
        // millisecond (Str::ulid() does) repeats the front of the random part.
        $sameMillisecond = 0;

        foreach (array_slice($ids, 1, null, true) as $position => $id) {
            $previous = $ids[$position - 1];

            if (substr($id, 0, 10) === substr($previous, 0, 10)) {
                $sameMillisecond++;
                $this->assertNotSame(substr($previous, 10, 8), substr($id, 10, 8));
            }
        }

        $this->assertGreaterThan(0, $sameMillisecond);
    }
}
