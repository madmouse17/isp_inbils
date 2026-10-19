<?php

namespace Tests\Support;

use App\Services\Core\IntegrationConfig;
use LogicException;
use Modules\NetworkAsset\Services\Radius\DisconnectResult;
use Modules\NetworkAsset\Services\Radius\RadiusDisconnectClient;
use Modules\NetworkAsset\Services\Radius\RadiusException;

/**
 * In-memory Disconnect sender: records every call (never the secret) and answers per host from a script.
 * Answers: 'ack', 'nak:<cause>', 'timeout'. A host with no script is a stray call and fails the test.
 */
class FakeDisconnectClient extends RadiusDisconnectClient
{
    /** @var array<string, string> */
    private array $answers = [];

    /** @var list<array{host: string, port: int, attributes: array<string, string>, has_secret: bool}> */
    private array $calls = [];

    public function answer(string $host, string $answer): void
    {
        $this->answers[strtolower(trim($host, '[]'))] = $answer;
    }

    /** @return list<array{host: string, port: int, attributes: array<string, string>, has_secret: bool}> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function disconnect(string $host, int $port, string $secret, array $attributes): DisconnectResult
    {
        if (! app(IntegrationConfig::class)->isEnabled('radius')) {
            throw RadiusException::disabled();
        }

        $this->calls[] = ['host' => $host, 'port' => $port, 'attributes' => $attributes, 'has_secret' => $secret !== ''];
        $answer = $this->answers[strtolower(trim($host, '[]'))]
            ?? throw new LogicException("Stray Disconnect-Request to {$host}:{$port}");

        return match (true) {
            $answer === 'ack' => new DisconnectResult('ack'),
            $answer === 'timeout' => new DisconnectResult('timeout'),
            str_starts_with($answer, 'nak:') => new DisconnectResult('nak', (int) substr($answer, 4)),
            default => throw new LogicException("Unknown scripted answer {$answer}"),
        };
    }
}
