<?php

namespace Tests\Feature\Api\V1\Platform;

use App\Support\Database\PublicIdColumn;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * The table the tokens live in, and the daily prune of the ones that expired.
 */
class TokenHousekeepingTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    public function test_expired_tokens_are_pruned_a_day_after_they_expire(): void
    {
        $this->travelTo(Carbon::create(2026, 10, 12, 10, 0, 0, 'UTC'));
        [$user] = $this->customerLogin();
        $stale = $user->createToken('Stale', ['customer'], now()->subHours(25))->accessToken;
        $recent = $user->createToken('Recent', ['customer'], now()->subHours(2))->accessToken;
        $live = $user->createToken('Live', ['customer'], now()->addDay())->accessToken;

        $this->artisan('sanctum:prune-expired', ['--hours' => 24])->assertExitCode(0);

        $this->assertNull(PersonalAccessToken::query()->find($stale->id));
        $this->assertNotNull(PersonalAccessToken::query()->find($recent->id), 'inside the 24-hour grace');
        $this->assertNotNull(PersonalAccessToken::query()->find($live->id));
    }

    public function test_the_prune_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('sanctum:prune-expired --hours=24')->assertExitCode(0);

        $event = collect(app(Schedule::class)->events())->first(fn (Event $event): bool => str_contains($event->command, 'sanctum:prune-expired'));
        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
    }

    public function test_the_tokens_table_rolls_back_and_forward(): void
    {
        $migration = $this->migration();
        // device_tokens references personal_access_tokens, so it must go first and come back last.
        $deviceTokens = require database_path('migrations/2026_10_21_100000_create_device_tokens_table.php');

        try {
            $deviceTokens->down();
            $migration->down();
            $this->assertFalse(Schema::hasTable('personal_access_tokens'));

            $migration->up();
            $this->assertTrue(Schema::hasTable('personal_access_tokens'));
            foreach (['id', 'tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'last_used_at', 'expires_at', 'created_at', 'updated_at'] as $column) {
                $this->assertTrue(Schema::hasColumn('personal_access_tokens', $column), $column);
            }
            $indexed = collect(Schema::getIndexes('personal_access_tokens'));
            $this->assertTrue($indexed->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['token']), 'the token hash is unique');
            $this->assertTrue($indexed->contains(fn (array $index): bool => $index['columns'] === ['expires_at']), 'the prune filters on expires_at');
            $this->assertTrue($indexed->contains(fn (array $index): bool => $index['columns'] === ['tokenable_type', 'tokenable_id']), 'revocation looks tokens up by owner');
        } finally {
            if (! Schema::hasTable('personal_access_tokens')) {
                $migration->up();
            }
            if (! Schema::hasTable('device_tokens')) {
                $deviceTokens->up();
            }

            PublicIdColumn::add(['personal_access_tokens', 'device_tokens']);
        }
    }

    private function migration(): Migration
    {
        $files = glob(database_path('migrations/*_create_personal_access_tokens_table.php'));
        $this->assertCount(1, $files, 'exactly one migration creates the tokens table');

        return require $files[0];
    }
}
