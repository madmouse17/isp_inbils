<?php

namespace Tests\Traits;

use Modules\NetworkAsset\Services\Radius\RadiusDisconnectClient;
use Tests\Support\FakeDisconnectClient;

/**
 * Fake NAS CoA/Disconnect endpoint: binds a recording sender in the container, so no UDP packet ever leaves.
 *
 *   $this->coaAnswers('192.0.2.10', 'ack');          // ack | nak:<cause> | timeout, per NAS address
 *   $this->disconnectsSent();                        // host, port, attributes, has_secret of every call
 */
trait InteractsWithNasCoa
{
    private ?FakeDisconnectClient $fakeCoa = null;

    private function fakeCoa(): FakeDisconnectClient
    {
        if ($this->fakeCoa === null) {
            $this->fakeCoa = new FakeDisconnectClient();
            $this->app->instance(RadiusDisconnectClient::class, $this->fakeCoa);
        }

        return $this->fakeCoa;
    }

    protected function coaAnswers(string $host, string $answer): void
    {
        $this->fakeCoa()->answer($host, $answer);
    }

    /** @return list<array{host: string, port: int, attributes: array<string, string>, has_secret: bool}> */
    protected function disconnectsSent(): array
    {
        return $this->fakeCoa()->calls();
    }
}
