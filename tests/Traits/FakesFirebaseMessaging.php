<?php

namespace Tests\Traits;

use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Mockery;

/**
 * Push switched on with a Messaging client that never leaves the process: the credentials path is set (so the channel
 * is armed) and every sendMulticast() call is recorded, answered per token as the test says. Nothing reaches FCM.
 */
trait FakesFirebaseMessaging
{
    /** @var list<array{message: array<string, mixed>, tokens: list<string>, validate_only: bool}> */
    protected array $multicasts = [];

    /** @var array<string, MessagingException> the error FCM answers for a token; every other token succeeds */
    protected array $fcmAnswers = [];

    protected function fakeFirebase(): void
    {
        $this->multicasts = [];
        $this->fcmAnswers = [];

        config(['firebase.projects.app.credentials' => '/run/secrets/firebase-service-account.json']);

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->andReturnUsing(function (CloudMessage $message, array $tokens, bool $validateOnly = false): MulticastSendReport {
            $this->multicasts[] = ['message' => $message->jsonSerialize(), 'tokens' => $tokens, 'validate_only' => $validateOnly];

            return MulticastSendReport::withItems(array_map(
                fn (string $token): SendReport => isset($this->fcmAnswers[$token])
                    ? SendReport::failure(MessageTarget::with(MessageTarget::TOKEN, $token), $this->fcmAnswers[$token])
                    : SendReport::success(MessageTarget::with(MessageTarget::TOKEN, $token), ['name' => 'projects/inbils/messages/0:1']),
                $tokens,
            ));
        });

        $this->instance(Messaging::class, $messaging);
    }

    /** The token FCM no longer knows (the app was uninstalled): a 404 UNREGISTERED. */
    protected function fcmUnregistered(string $token): void
    {
        $this->fcmAnswers[$token] = NotFound::becauseTokenNotFound($token);
    }

    /** A token that is not an FCM registration token at all: a 400 INVALID_ARGUMENT naming the token. */
    protected function fcmInvalid(string $token): void
    {
        $this->fcmAnswers[$token] = new InvalidMessage('The registration token is not a valid FCM registration token');
    }

    /** A failure worth retrying: FCM answers 503 for this token. */
    protected function fcmUnavailable(string $token): void
    {
        $this->fcmAnswers[$token] = new ServerUnavailable('The service is currently unavailable.');
    }

    /** @return list<string> the tokens of every multicast, in the order they were sent */
    protected function pushedTokens(): array
    {
        return array_merge(...array_column($this->multicasts, 'tokens') ?: [[]]);
    }

    /** @return array<string, mixed> the CloudMessage of the only multicast */
    protected function onlyPush(): array
    {
        $this->assertCount(1, $this->multicasts, 'exactly one push was expected');

        return $this->multicasts[0]['message'];
    }
}
