<?php

namespace Tests\Unit\Support;

use App\Support\FieldCheckIn;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FieldCheckInTest extends TestCase
{
    public function test_a_fix_is_stored_with_the_metres_to_the_site(): void
    {
        // 0.001 degree of latitude is 111.19 m.
        $properties = FieldCheckIn::properties(
            ['lat' => '-6.1743924', 'lng' => 106.8271528, 'accuracy_m' => '12.5', 'captured_at' => '2026-10-10T08:30:00+07:00'],
            ['lat' => '-6.1753924', 'lng' => '106.8271528'],
        );

        $this->assertSame([
            'status' => 'recorded',
            'lat' => -6.1743924,
            'lng' => 106.8271528,
            'accuracy_m' => 12.5,
            'captured_at' => '2026-10-10T01:30:00+00:00',
            'distance_m' => 111,
        ], $properties);
    }

    public function test_a_fix_on_the_site_is_zero_metres_away(): void
    {
        $this->assertSame(0, FieldCheckIn::properties(['lat' => -6.2, 'lng' => 106.8], ['lat' => -6.2, 'lng' => 106.8])['distance_m']);
    }

    public function test_the_distance_is_great_circle_over_long_ranges(): void
    {
        // Jakarta to Bandung is about 116 km.
        $distance = FieldCheckIn::properties(['lat' => -6.2088, 'lng' => 106.8456], ['lat' => -6.9175, 'lng' => 107.6191])['distance_m'];

        $this->assertEqualsWithDelta(116_000, $distance, 2_000);
    }

    public function test_a_fix_without_a_site_has_no_distance_and_optional_parts_stay_null(): void
    {
        $this->assertSame([
            'status' => 'recorded',
            'lat' => -6.2,
            'lng' => 106.8,
            'accuracy_m' => null,
            'captured_at' => null,
            'distance_m' => null,
        ], FieldCheckIn::properties(['lat' => -6.2, 'lng' => 106.8], null));
    }

    public function test_no_fix_is_stored_as_unavailable_with_the_reason(): void
    {
        $this->assertSame(
            ['status' => 'unavailable', 'reason' => 'permission_denied'],
            FieldCheckIn::properties(['unavailable_reason' => 'permission_denied'], ['lat' => -6.2, 'lng' => 106.8]),
        );
    }

    public function test_the_site_is_the_first_place_that_has_both_coordinates(): void
    {
        $withoutCoordinates = (object) ['lat' => null, 'lng' => null];
        $halfWay = (object) ['lat' => '-6.2000000', 'lng' => null];
        $installed = (object) ['lat' => '-6.2000000', 'lng' => '106.8000000'];
        $other = (object) ['lat' => '1.0000000', 'lng' => '2.0000000'];

        $this->assertSame(['lat' => '-6.2000000', 'lng' => '106.8000000'], FieldCheckIn::site(null, $withoutCoordinates, $halfWay, $installed, $other));
        $this->assertNull(FieldCheckIn::site(null, $withoutCoordinates, $halfWay));
        $this->assertNull(FieldCheckIn::site());
    }

    public function test_a_place_may_also_be_a_plain_array_such_as_a_resolved_site(): void
    {
        $this->assertSame(['lat' => -6.2, 'lng' => 106.8], FieldCheckIn::site(['label' => 'Rumah', 'address' => null, 'lat' => -6.2, 'lng' => 106.8]));
        $this->assertNull(FieldCheckIn::site(['label' => 'Rumah', 'address' => 'Jl. Mawar 1', 'lat' => null, 'lng' => null]));
        $this->assertSame(['lat' => 1.0, 'lng' => 2.0], FieldCheckIn::site(null, ['lat' => null, 'lng' => null], ['lat' => 1.0, 'lng' => 2.0]));
    }

    /** @param  array<string, mixed>  $input */
    #[DataProvider('validInputs')]
    public function test_valid_check_ins_pass_and_only_known_keys_are_kept(array $input): void
    {
        $validator = Validator::make($input, FieldCheckIn::rules());

        $this->assertTrue($validator->passes(), json_encode($validator->errors()->toArray()) ?: '');
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function validInputs(): array
    {
        return [
            'no check-in at all' => [[]],
            'a null check-in' => [['check_in' => null]],
            'an empty check-in object' => [['check_in' => []]],
            'a fix' => [['check_in' => ['lat' => -6.2, 'lng' => 106.8]]],
            'a full fix' => [['check_in' => ['lat' => '-6.2', 'lng' => '106.8', 'accuracy_m' => '12.5', 'captured_at' => '2026-10-10T08:30:00+07:00']]],
            'the edges of the globe' => [['check_in' => ['lat' => 90, 'lng' => -180, 'accuracy_m' => 0]]],
            'a reason' => [['check_in' => ['unavailable_reason' => 'permission_denied']]],
            'every reason' => [['check_in' => ['unavailable_reason' => 'timeout']]],
        ];
    }

    /** @param  array<string, mixed>  $input */
    #[DataProvider('invalidInputs')]
    public function test_invalid_check_ins_fail_on_the_named_field(array $input, string $field): void
    {
        $errors = Validator::make($input, FieldCheckIn::rules())->errors();

        $this->assertTrue($errors->has($field), "expected an error on {$field}, got ".json_encode($errors->toArray()));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidInputs(): array
    {
        return [
            'a check-in that is not an object' => [['check_in' => 'here'], 'check_in'],
            'latitude without longitude' => [['check_in' => ['lat' => -6.2]], 'check_in.lng'],
            'longitude without latitude' => [['check_in' => ['lng' => 106.8]], 'check_in.lat'],
            'only an accuracy' => [['check_in' => ['accuracy_m' => 5]], 'check_in.lat'],
            'latitude out of range' => [['check_in' => ['lat' => 90.5, 'lng' => 106.8]], 'check_in.lat'],
            'longitude out of range' => [['check_in' => ['lat' => -6.2, 'lng' => 180.5]], 'check_in.lng'],
            'latitude that is text' => [['check_in' => ['lat' => 'north', 'lng' => 106.8]], 'check_in.lat'],
            'a negative accuracy' => [['check_in' => ['lat' => -6.2, 'lng' => 106.8, 'accuracy_m' => -1]], 'check_in.accuracy_m'],
            'an absurd accuracy' => [['check_in' => ['lat' => -6.2, 'lng' => 106.8, 'accuracy_m' => 100001]], 'check_in.accuracy_m'],
            'a captured_at that is not a date' => [['check_in' => ['lat' => -6.2, 'lng' => 106.8, 'captured_at' => 'when']], 'check_in.captured_at'],
            'an unknown reason' => [['check_in' => ['unavailable_reason' => 'battery']], 'check_in.unavailable_reason'],
            'a fix and a reason together' => [['check_in' => ['lat' => -6.2, 'lng' => 106.8, 'unavailable_reason' => 'no_fix']], 'check_in.unavailable_reason'],
        ];
    }
}
