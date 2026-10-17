<?php

namespace App\Console\Commands;

use App\Models\Core\DeviceToken;
use App\Models\User;
use App\Services\Core\ApiTokenService;
use App\Services\Push\FcmSender;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PushTestCommand extends Command
{
    protected $signature = 'inbils:push:test
        {user : Id or email of the user}
        {--app= : customer or technician; default every app the user has an install of}
        {--dry-run : Ask FCM to validate the request and the credentials without delivering anything}';

    protected $description = 'Send a test push to the installs of a user, to smoke-test Firebase on a device.';

    public function handle(): int
    {
        if (! FcmSender::isConfigured()) {
            $this->error('Push is off: FIREBASE_CREDENTIALS is empty. See docs/operations/push-notifications.md.');

            return self::FAILURE;
        }

        $app = $this->option('app');

        if ($app !== null && ! in_array($app, ApiTokenService::APPS, true)) {
            $this->error('--app must be '.implode(' or ', ApiTokenService::APPS).'.');

            return self::FAILURE;
        }

        $argument = (string) $this->argument('user');
        $user = User::query()->where(ctype_digit($argument) ? 'id' : 'email', $argument)->first();

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $installs = DeviceToken::query()->where('user_id', $user->id)->when($app, fn ($query) => $query->where('app', $app))->reachable()->get();

        if ($installs->isEmpty()) {
            $this->error("{$user->email} has no install a push can reach: sign in to the app first so it registers its token.");

            return self::FAILURE;
        }

        $sender = $this->laravel->make(FcmSender::class);
        $delivered = false;

        foreach ($installs->groupBy('app') as $appName => $group) {
            $result = $sender->send($group, $this->message(), (bool) $this->option('dry-run'));
            $delivered = $delivered || $result['sent'] > 0;

            $this->line("{$appName}: {$result['sent']} accepted, {$result['stale']} stale".($this->option('dry-run') ? ' (kept, dry run)' : ' (deleted)').", {$result['failed']} failed.");
        }

        return $delivered ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    private function message(): array
    {
        return [
            'title' => 'Inbils',
            'body' => 'Tes notifikasi push dari server.',
            'data' => [
                'type' => 'push_test',
                'subject_type' => '',
                'subject_id' => '',
                'notification_id' => (string) Str::uuid(),
                'title' => 'Inbils',
                'body' => 'Tes notifikasi push dari server.',
            ],
        ];
    }
}
