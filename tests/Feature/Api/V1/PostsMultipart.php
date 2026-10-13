<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A multipart request to /api/v1: InteractsWithMobileApi::api() sends JSON, which cannot carry a file. Like api(),
 * it forgets the guard the previous request left behind and brings a fresh Idempotency-Key unless one is given.
 */
trait PostsMultipart
{
    /** @param  array<string, mixed>  $data */
    protected function multipart(string $uri, array $data, string $token, ?string $key = null): TestResponse
    {
        app('auth')->forgetGuards();
        app('auth')->shouldUse('web');

        return $this->post('/api/v1/'.ltrim($uri, '/'), $data, [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }
}
