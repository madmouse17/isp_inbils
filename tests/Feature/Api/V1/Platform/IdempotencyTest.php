<?php

namespace Tests\Feature\Api\V1\Platform;

use App\Http\Middleware\Api\EnforceIdempotency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * Every mutation under api.* needs an Idempotency-Key, and a retry of the same request never applies twice.
 */
class IdempotencyTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private int $runs = 0;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->user] = $this->customerLogin();
        $this->token = $this->issueToken($this->user, 'customer');

        Route::middleware(['api', 'api.customer'])->prefix('api/v1/_probe')->group(function (): void {
            Route::post('create', function (Request $request) {
                $this->runs++;

                return response()->json(['run' => $this->runs, 'name' => $request->input('name'), 'key' => $request->attributes->get(EnforceIdempotency::KEY_ATTRIBUTE)], 201);
            });
            Route::put('create', fn () => response()->json(['run' => ++$this->runs], 200));
            Route::delete('thing', function () {
                $this->runs++;

                return response()->noContent();
            });
            Route::post('refuse', function () {
                $this->runs++;

                return response()->json(['message' => 'No.'], 403);
            });
            Route::post('flaky', function () {
                if (++$this->runs === 1) {
                    return response()->json(['message' => 'Boom.'], 500);
                }

                return response()->json(['run' => $this->runs], 201);
            });
            Route::post('throttled', function () {
                if (++$this->runs === 1) {
                    return response()->json(['message' => 'Slow down.'], 429);
                }

                return response()->json(['run' => $this->runs], 201);
            });
            Route::post('upload', function (Request $request) {
                $this->runs++;

                return response()->json(['run' => $this->runs, 'caption' => $request->input('caption')], 201);
            });
            Route::get('read', fn () => response()->json(['ok' => true]));
        });
    }

    public function test_a_replay_returns_the_same_status_and_the_same_bytes_without_running_the_action_again(): void
    {
        $key = (string) Str::uuid();

        $first = $this->api('POST', '_probe/create', ['name' => 'A'], $this->token, ['Idempotency-Key' => $key]);
        $replay = $this->api('POST', '_probe/create', ['name' => 'A'], $this->token, ['Idempotency-Key' => $key]);

        $first->assertCreated()->assertJsonPath('run', 1)->assertHeaderMissing('Idempotent-Replayed');
        $replay->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->getContent(), $replay->getContent());
        $this->assertSame('application/json', $replay->headers->get('Content-Type'));
        $this->assertSame(1, $this->runs);
    }

    public function test_the_same_key_with_another_body_is_a_422_and_the_action_stays_untouched(): void
    {
        $key = (string) Str::uuid();
        $this->api('POST', '_probe/create', ['name' => 'A'], $this->token, ['Idempotency-Key' => $key])->assertCreated();

        $this->api('POST', '_probe/create', ['name' => 'B'], $this->token, ['Idempotency-Key' => $key])
            ->assertStatus(422)
            ->assertJsonPath('code', 'idempotency_conflict');

        $this->assertSame(1, $this->runs);
    }

    public function test_the_same_key_on_another_method_is_a_conflict_too(): void
    {
        $key = (string) Str::uuid();
        $this->api('POST', '_probe/create', ['name' => 'A'], $this->token, ['Idempotency-Key' => $key])->assertCreated();

        $this->api('PUT', '_probe/create', ['name' => 'A'], $this->token, ['Idempotency-Key' => $key])
            ->assertStatus(422)
            ->assertJsonPath('code', 'idempotency_conflict');
    }

    public function test_a_key_belongs_to_the_user_who_used_it(): void
    {
        $key = (string) Str::uuid();
        [$other] = $this->customerLogin();
        $this->api('POST', '_probe/create', ['name' => 'A'], $this->token, ['Idempotency-Key' => $key])->assertCreated();

        $this->api('POST', '_probe/create', ['name' => 'A'], $this->issueToken($other, 'customer'), ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertJsonPath('run', 2);
    }

    public function test_a_duplicate_that_arrives_while_the_first_is_still_running_is_a_409(): void
    {
        $key = (string) Str::uuid();
        Cache::put(EnforceIdempotency::cacheKey($this->user->id, $key), ['state' => 'in_flight'], 60);

        $this->api('POST', '_probe/create', ['name' => 'A'], $this->token, ['Idempotency-Key' => $key])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_in_progress');

        $this->assertSame(0, $this->runs);
    }

    public function test_the_action_holds_the_key_while_it_runs(): void
    {
        $key = (string) Str::uuid();
        $during = null;
        Route::middleware(['api', 'api.customer'])->prefix('api/v1/_probe')->post('watch', function () use (&$during, $key) {
            $during = Cache::get(EnforceIdempotency::cacheKey($this->user->id, $key));

            return response()->json(['ok' => true], 201);
        });

        $this->api('POST', '_probe/watch', [], $this->token, ['Idempotency-Key' => $key])->assertCreated();

        $this->assertSame('in_flight', $during['state']);
        $this->assertSame('done', Cache::get(EnforceIdempotency::cacheKey($this->user->id, $key))['state']);
    }

    public function test_a_mutation_without_a_key_is_a_428(): void
    {
        $this->api('POST', '_probe/create', ['name' => 'A'], $this->token, ['Idempotency-Key' => null])
            ->assertStatus(428)
            ->assertJsonPath('code', 'idempotency_key_required');
        $this->api('DELETE', '_probe/thing', [], $this->token, ['Idempotency-Key' => null])->assertStatus(428);

        $this->assertSame(0, $this->runs);
    }

    public function test_a_key_that_is_not_a_uuid_is_a_validation_failure(): void
    {
        $this->api('POST', '_probe/create', ['name' => 'A'], $this->token, ['Idempotency-Key' => 'not-a-uuid'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['Idempotency-Key']);

        $this->assertSame(0, $this->runs);
    }

    public function test_reads_need_no_key(): void
    {
        $this->api('GET', '_probe/read', [], $this->token)->assertOk();
    }

    public function test_the_validated_key_reaches_the_controller_in_lower_case(): void
    {
        $key = (string) Str::uuid();

        $this->api('POST', '_probe/create', ['name' => 'A'], $this->token, ['Idempotency-Key' => strtoupper($key)])
            ->assertCreated()
            ->assertJsonPath('key', $key);
    }

    public function test_a_client_error_is_replayed_like_a_success(): void
    {
        $key = (string) Str::uuid();

        $first = $this->api('POST', '_probe/refuse', [], $this->token, ['Idempotency-Key' => $key])->assertForbidden();
        $replay = $this->api('POST', '_probe/refuse', [], $this->token, ['Idempotency-Key' => $key])->assertForbidden();

        $this->assertSame($first->getContent(), $replay->getContent());
        $this->assertSame(1, $this->runs);
    }

    public function test_a_5xx_is_not_stored_so_the_retry_with_the_same_key_runs_again(): void
    {
        $key = (string) Str::uuid();

        $this->api('POST', '_probe/flaky', [], $this->token, ['Idempotency-Key' => $key])->assertStatus(500);
        $this->assertNull(Cache::get(EnforceIdempotency::cacheKey($this->user->id, $key)));

        $this->api('POST', '_probe/flaky', [], $this->token, ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('run', 2);
    }

    public function test_a_429_is_not_stored_either(): void
    {
        $key = (string) Str::uuid();

        $this->api('POST', '_probe/throttled', [], $this->token, ['Idempotency-Key' => $key])->assertStatus(429);
        $this->api('POST', '_probe/throttled', [], $this->token, ['Idempotency-Key' => $key])->assertCreated();
    }

    public function test_an_empty_204_is_replayed(): void
    {
        $key = (string) Str::uuid();

        $this->api('DELETE', '_probe/thing', [], $this->token, ['Idempotency-Key' => $key])->assertNoContent();
        $this->api('DELETE', '_probe/thing', [], $this->token, ['Idempotency-Key' => $key])->assertNoContent()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, $this->runs);
    }

    public function test_the_stored_entry_expires_after_24_hours(): void
    {
        $key = (string) Str::uuid();
        $this->api('POST', '_probe/create', ['name' => 'A'], $this->token, ['Idempotency-Key' => $key])->assertCreated();

        $this->travel(24)->hours();
        $this->travel(1)->minutes();

        $this->api('POST', '_probe/create', ['name' => 'A'], $this->token, ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertJsonPath('run', 2);
    }

    public function test_an_upload_is_fingerprinted_by_the_file_bytes_not_by_the_multipart_boundary(): void
    {
        $key = (string) Str::uuid();
        $send = fn (string $bytes) => $this->multipart('_probe/upload', ['caption' => 'Photo', 'file' => UploadedFile::fake()->createWithContent('a.jpg', $bytes)], $key);

        $send('same bytes')->assertCreated()->assertJsonPath('run', 1);
        $send('same bytes')->assertCreated()->assertJsonPath('run', 1)->assertHeader('Idempotent-Replayed', 'true');
        $send('other bytes')->assertStatus(422)->assertJsonPath('code', 'idempotency_conflict');

        $this->assertSame(1, $this->runs);
    }

    public function test_an_upload_with_another_caption_is_a_conflict(): void
    {
        $key = (string) Str::uuid();
        $file = fn () => UploadedFile::fake()->createWithContent('a.jpg', 'same bytes');

        $this->multipart('_probe/upload', ['caption' => 'One', 'file' => $file()], $key)->assertCreated();
        $this->multipart('_probe/upload', ['caption' => 'Two', 'file' => $file()], $key)->assertStatus(422);
    }

    /** @param  array<string, mixed>  $data */
    private function multipart(string $uri, array $data, string $key)
    {
        app('auth')->forgetGuards();
        app('auth')->shouldUse('web');

        return $this->post('/api/v1/'.$uri, $data, ['Authorization' => 'Bearer '.$this->token, 'Idempotency-Key' => $key, 'Accept' => 'application/json']);
    }
}
