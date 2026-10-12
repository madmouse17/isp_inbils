<?php

namespace Modules\NetworkAsset\Services;

use Modules\NetworkAsset\Models\NasDevice;
use RouterOS\Client;
use RouterOS\Exceptions\BadCredentialsException;
use RouterOS\Exceptions\ClientException;
use RouterOS\Exceptions\ConnectException;
use RouterOS\Exceptions\StreamException;
use RouterOS\Query;

/**
 * MikroTik RouterOS binary API (RouterOS 6 and 7): the `api` service on 8728 or the `api-ssl` service on 8729,
 * through evilfreelancer/routeros-api-php. Same switch, shapes and error kinds as RouterOsClient.
 *
 * Every port except 8728 is TLS and always verifies the router certificate (ROUTEROS_CA_BUNDLE or the system store); there is no
 * skip-verification switch. Port 8728 is plain TCP: the API password and every command cross the network in
 * clear text. It is allowed only because the owner picked that port for the router; use it on a trusted
 * management network only. One connection per call, always closed.
 */
class RouterOsBinaryClient implements RouterOsApi
{
    use ConvertsRouterOsRecords;

    private const CONNECT_TIMEOUT = 5;

    private const READ_TIMEOUT = 10;

    /** The only plain-text port (the `api` service default); every other port is TLS, so a custom api-ssl port never leaks credentials. */
    private const PLAIN_PORT = 8728;

    /** Stream errors that mean the certificate or the TLS handshake, not the network. */
    private const TLS_FAILURE = '/SSL|certificate|crypto|handshake/i';

    /** @return array{version: string, board_name: string, uptime: string} */
    public function systemResource(NasDevice $nas): array
    {
        $rows = $this->run($nas, '/system/resource/print')['rows'];

        if (count($rows) !== 1) {
            throw $this->tagged($nas, RouterOsException::server('The router answered with an unexpected system resource.'));
        }

        return [
            'version' => (string) ($rows[0]['version'] ?? ''),
            'board_name' => (string) ($rows[0]['board-name'] ?? ''),
            'uptime' => (string) ($rows[0]['uptime'] ?? ''),
        ];
    }

    /** @return array{id: string, name: string, profile: ?string, disabled: bool, comment: ?string, service: ?string}|null */
    public function find(NasDevice $nas, string $type, string $name): ?array
    {
        $rows = $this->run($nas, $this->menu($type, 'accounts').'/print', ['?name='.$name, '=.proplist='.$this->accountFields($type)])['rows'];

        return $rows === [] ? null : $this->account($rows[0]);
    }

    /**
     * @param  array<string, string|bool>  $fields
     * @return string the new record id
     */
    public function create(NasDevice $nas, string $type, array $fields): string
    {
        $ret = $this->run($nas, $this->menu($type, 'accounts').'/add', $this->assignments($fields))['ret'];

        try {
            return $this->recordId($ret);
        } catch (RouterOsException $e) {
            throw $this->tagged($nas, $e);
        }
    }

    /** @param  array<string, string|bool>  $fields */
    public function update(NasDevice $nas, string $type, string $id, array $fields): void
    {
        $this->run($nas, $this->menu($type, 'accounts').'/set', ['=.id='.$this->checkedId($nas, $id), ...$this->assignments($fields)]);
    }

    /** A record that is already gone counts as removed. */
    public function remove(NasDevice $nas, string $type, string $id): void
    {
        $this->run($nas, $this->menu($type, 'accounts').'/remove', ['=.id='.$this->checkedId($nas, $id)], notFoundIsFine: true);
    }

    /** @return list<array{id: string, name: string, address: ?string}> */
    public function activeSessions(NasDevice $nas, string $type, ?string $name = null): array
    {
        $login = self::MENUS[$type]['login'];
        $words = ["=.proplist=.id,{$login},address"];

        if ($name !== null) {
            array_unshift($words, "?{$login}={$name}");
        }

        return array_map(
            fn (array $row): array => ['id' => (string) ($row['.id'] ?? ''), 'name' => (string) ($row[$login] ?? ''), 'address' => $this->text($row['address'] ?? null)],
            $this->run($nas, $this->menu($type, 'sessions').'/print', $words)['rows'],
        );
    }

    /** Ends one session. A session that is already gone counts as ended. */
    public function disconnect(NasDevice $nas, string $type, string $activeId): void
    {
        $this->run($nas, $this->menu($type, 'sessions').'/remove', ['=.id='.$this->checkedId($nas, $activeId)], notFoundIsFine: true);
    }

    /** @return list<array{id: string, name: string, profile: ?string, disabled: bool, comment: ?string, service: ?string}> */
    public function listAccounts(NasDevice $nas, string $type): array
    {
        return array_map($this->account(...), $this->run($nas, $this->menu($type, 'accounts').'/print', ['=.proplist='.$this->accountFields($type)])['rows']);
    }

    /**
     * One command on a fresh connection.
     *
     * @param  list<string>  $words
     * @return array{rows: list<array<string, string>>, ret: ?string}
     */
    private function run(NasDevice $nas, string $command, array $words = [], bool $notFoundIsFine = false): array
    {
        try {
            return $this->perform($nas, $command, $words, $notFoundIsFine);
        } catch (RouterOsException $e) {
            throw $this->tagged($nas, $e);
        }
    }

    /**
     * @param  list<string>  $words
     * @return array{rows: list<array<string, string>>, ret: ?string}
     */
    private function perform(NasDevice $nas, string $command, array $words, bool $notFoundIsFine): array
    {
        if (! config('services.routeros.enabled')) {
            throw RouterOsException::disabled();
        }

        $client = $this->connect($nas);

        try {
            return $this->parse($client->query(new Query($command, $words))->read(false), $notFoundIsFine);
        } catch (RouterOsException $e) {
            throw $e;
        } catch (ClientException|StreamException) {
            throw RouterOsException::unreachable(); // a broken or timed-out stream mid-conversation
        } finally {
            $socket = $client->getSocket();

            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    private function connect(NasDevice $nas): Client
    {
        $tls = $nas->api_port !== self::PLAIN_PORT;
        $bundle = config('services.routeros.ca_bundle');
        $config = [
            'host' => $this->managementHost($nas),
            'user' => $nas->api_username,
            'pass' => $nas->api_password,
            'port' => $nas->api_port,
            'ssl' => $tls,
            'timeout' => self::CONNECT_TIMEOUT,
            'socket_timeout' => self::READ_TIMEOUT,
            'attempts' => 1,
            'delay' => 0,
        ];

        if ($tls) {
            // The library default is anonymous DH without any certificate check.
            $config['ssl_options'] = ['ciphers' => 'DEFAULT', 'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]
                + (is_string($bundle) && $bundle !== '' ? ['cafile' => $bundle] : []);
        }

        try {
            return app(Client::class, ['config' => $config]);
        } catch (BadCredentialsException) {
            throw RouterOsException::auth();
        } catch (ConnectException $e) {
            throw preg_match(self::TLS_FAILURE, $e->getMessage()) ? RouterOsException::tls() : RouterOsException::unreachable();
        } catch (ClientException|StreamException) {
            throw RouterOsException::unreachable();
        }
    }

    /**
     * Raw reply words (`!re`, `!trap`, `!done`, `!fatal` and `=key=value`) into rows and the `=ret=` of an add.
     *
     * @param  list<string>  $reply
     * @return array{rows: list<array<string, string>>, ret: ?string}
     */
    private function parse(array $reply, bool $notFoundIsFine): array
    {
        $rows = [];
        $trap = null;
        $ret = null;
        $current = null;

        foreach ($reply as $word) {
            if ($word === '!fatal') {
                throw RouterOsException::server('The router closed the API connection.');
            }

            if (in_array($word, ['!re', '!trap', '!done'], true)) {
                $current = $word;

                if ($word === '!re') {
                    $rows[] = [];
                } elseif ($word === '!trap') {
                    $trap = '';
                }

                continue;
            }

            if (! preg_match('/^=([^=]+)=(.*)$/s', $word, $match)) {
                continue;
            }

            match ($current) {
                '!re' => $rows[array_key_last($rows)][$match[1]] = $match[2],
                '!trap' => $match[1] === 'message' ? $trap = $match[2] : null,
                '!done' => $match[1] === 'ret' ? $ret = $match[2] : null,
                default => null,
            };
        }

        if ($trap !== null) {
            return $this->trapped($trap, $notFoundIsFine);
        }

        return ['rows' => $rows, 'ret' => $ret];
    }

    /** @return array{rows: list<array<string, string>>, ret: ?string} */
    private function trapped(string $message, bool $notFoundIsFine): array
    {
        if ($notFoundIsFine && stripos($message, 'no such item') !== false) {
            return ['rows' => [], 'ret' => null];
        }

        throw stripos($message, 'not enough permissions') !== false
            ? RouterOsException::auth()
            : RouterOsException::rejected($this->cleanMessage($message) ?: 'The router rejected the request.');
    }

    /**
     * @param  array<string, string|bool>  $fields
     * @return list<string>
     */
    private function assignments(array $fields): array
    {
        $words = [];

        foreach ($this->strings($fields) as $key => $value) {
            $words[] = "={$key}={$value}";
        }

        return $words;
    }

    private function menu(string $type, string $kind): string
    {
        return '/'.self::MENUS[$type][$kind];
    }

    private function checkedId(NasDevice $nas, string $id): string
    {
        try {
            return $this->recordId($id);
        } catch (RouterOsException $e) {
            throw $this->tagged($nas, $e);
        }
    }

    private function tagged(NasDevice $nas, RouterOsException $e): RouterOsException
    {
        $e->nasDeviceId = $nas->id;

        return $e;
    }
}
