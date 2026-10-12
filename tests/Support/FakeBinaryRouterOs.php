<?php

namespace Tests\Support;

use RouterOS\Client;
use RouterOS\Exceptions\BadCredentialsException;
use RouterOS\Exceptions\ClientException;
use RouterOS\Exceptions\ConnectException;
use RouterOS\Interfaces\ClientInterface;

/**
 * Stands in for the binary API library's Client: no socket, no network. The state (accounts, sessions,
 * profiles, failures) lives in the FakeRouterOs of the same host and port, so one fake router answers
 * both APIs. It speaks the wire words of the API: `!re`, `!trap`, `!done`, `!fatal`, `=key=value`.
 *
 * "Connecting" happens in the constructor, like the real client; getSocket() hands out an in-memory
 * stream so tests can assert the caller closed it.
 */
final class FakeBinaryRouterOs extends Client
{
    /** @var list<string> */
    private array $reply = [];

    /** @var resource */
    private $stream;

    /** @param  array<string, mixed>  $config the library config the caller built (fake credentials only) */
    public function __construct(private readonly FakeRouterOs $router, array $config)
    {
        $this->stream = fopen('php://memory', 'r+');
        $router->recordConnection($config + ['stream' => $this->stream]);

        match ($router->failure()) {
            'refused' => throw new ConnectException('Unable to establish socket session, Connection refused', 111),
            'timeout' => throw new ConnectException('Unable to establish socket session, Connection timed out', 110),
            'tls' => throw new ConnectException('Unable to establish socket session, SSL operation failed: certificate verify failed', 0),
            'auth' => throw new BadCredentialsException('Invalid user name or password'),
            default => null,
        };
    }

    public function getSocket()
    {
        return $this->stream;
    }

    /** @param  mixed  $endpoint a RouterOS\Query */
    public function query($endpoint, ?array $where = null, ?string $operations = null, ?string $tag = null): ClientInterface
    {
        [$command, $words] = [$endpoint->getEndpoint(), $endpoint->getAttributes()];
        $this->router->recordCommand($command, $words);

        $failure = $this->router->failure();
        $applies = $failure !== null && $this->router->failureApplies(ltrim($command, '/'));

        $this->reply = match (true) {
            $applies && $failure === 'server' => ['!fatal'],
            $applies && $failure === 'forbidden' => $this->trap('not enough permissions (9)'),
            $applies => $this->trap($this->router->failureDetail()),
            default => $this->execute($command, $words),
        };

        return $this;
    }

    /** @return list<string> */
    public function read(bool $parse = true, array $options = [])
    {
        if ($parse) {
            throw new ClientException('The fake only serves raw words: call read(false).');
        }

        return $this->reply;
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    private function execute(string $command, array $words): array
    {
        if ($command === '/system/resource/print') {
            return $this->rows([$this->router->resource]);
        }

        foreach (['pppoe' => ['/ppp/secret', '/ppp/active'], 'hotspot' => ['/ip/hotspot/user', '/ip/hotspot/active']] as $type => [$accounts, $sessions]) {
            if (str_starts_with($command, $accounts.'/')) {
                return $this->accountCommand($type, substr($command, strlen($accounts) + 1), $words);
            }

            if (str_starts_with($command, $sessions.'/')) {
                return $this->sessionCommand($type, substr($command, strlen($sessions) + 1), $words);
            }
        }

        return $this->trap('no such command');
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    private function accountCommand(string $type, string $action, array $words): array
    {
        [$filters, $attrs] = $this->split($words);

        if ($action === 'print') {
            return $this->rows($this->router->select($this->router->accountsById($type), $this->selection($filters, $attrs), ['password']));
        }

        if ($action === 'add') {
            return $this->add($type, $attrs);
        }

        $id = $attrs['.id'] ?? '';
        $row = $this->router->accountRow($type, $id);

        if ($row === null) {
            return $this->trap('no such item');
        }

        if ($action === 'remove') {
            $this->router->forgetAccount($type, $id);

            return ['!done'];
        }

        unset($attrs['.id']);

        if (isset($attrs['profile']) && ! $this->router->hasProfile($type, $attrs['profile'])) {
            return $this->trap('input does not match any value of profile');
        }

        $this->router->patchAccount($type, $id, $attrs);

        return ['!done'];
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    private function sessionCommand(string $type, string $action, array $words): array
    {
        [$filters, $attrs] = $this->split($words);

        if ($action === 'print') {
            return $this->rows($this->router->select($this->router->sessionsById($type), $this->selection($filters, $attrs)));
        }

        return $this->router->forgetSession($type, $attrs['.id'] ?? '') ? ['!done'] : $this->trap('no such item');
    }

    /**
     * @param  array<string, string>  $attrs
     * @return list<string>
     */
    private function add(string $type, array $attrs): array
    {
        $name = $attrs['name'] ?? '';

        if ($name === '') {
            return $this->trap('failure: name must be set');
        }

        if ($this->router->account($type, $name) !== null) {
            return $this->trap('failure: secret with the same name already exists');
        }

        if (isset($attrs['profile']) && ! $this->router->hasProfile($type, $attrs['profile'])) {
            return $this->trap('input does not match any value of profile');
        }

        $id = $this->router->seedAccount($type, $name, $attrs['profile'] ?? 'default', ($attrs['disabled'] ?? 'false') === 'true', $attrs['password'] ?? '', $attrs['comment'] ?? '');

        return ['!done', '=ret='.$id];
    }

    /**
     * @param  array<string, string>  $filters
     * @param  array<string, string>  $attrs
     * @return array<string, string>
     */
    private function selection(array $filters, array $attrs): array
    {
        return $filters + (isset($attrs['.proplist']) ? ['.proplist' => $attrs['.proplist']] : []);
    }

    /**
     * `?key=value` words are filters, `=key=value` words are attributes.
     *
     * @param  list<string>  $words
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function split(array $words): array
    {
        $filters = [];
        $attrs = [];

        foreach ($words as $word) {
            [$key, $value] = array_pad(explode('=', substr($word, 1), 2), 2, '');
            $word[0] === '?' ? $filters[$key] = $value : $attrs[$key] = $value;
        }

        return [$filters, $attrs];
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<string>
     */
    private function rows(array $rows): array
    {
        $reply = [];

        foreach ($rows as $row) {
            $reply[] = '!re';

            foreach ($row as $key => $value) {
                $reply[] = "={$key}={$value}";
            }
        }

        return [...$reply, '!done'];
    }

    /** @return list<string> */
    private function trap(string $message): array
    {
        return ['!trap', '=category=0', "=message={$message}", '!done'];
    }
}
