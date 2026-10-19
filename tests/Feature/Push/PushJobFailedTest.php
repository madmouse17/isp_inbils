<?php

namespace Tests\Feature\Push;

use App\Jobs\SendPushNotification;
use App\Models\Core\Company;
use App\Models\Core\DeviceToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

class PushJobFailedTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    public function test_a_final_push_failure_logs_a_warning_and_keeps_the_device_tokens(): void
    {
        $technician = $this->technicianLogin(Company::factory()->create(['is_active' => true]));
        $token = DeviceToken::factory()->create(['user_id' => $technician->id, 'app' => 'technician', 'token' => 'secret-install-token']);
        $job = new SendPushNotification($technician->id, 'technician', [
            'title' => 'Judul rahasia',
            'body' => 'Isi rahasia',
            'data' => ['notification_id' => 'n-1'],
        ], $token->id);
        Log::spy();

        $job->failed(new RuntimeException('FCM said secret-install-token is gone'));

        Log::shouldHaveReceived('warning')->once()->with('push.send_failed', [
            'notification_id' => 'n-1',
            'user_id' => $technician->id,
            'app' => 'technician',
            'device_token_id' => $token->id,
        ]);
        $this->assertModelExists($token);
    }
}
