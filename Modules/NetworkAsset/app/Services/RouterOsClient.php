<?php

namespace Modules\NetworkAsset\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\NetworkAsset\Models\NasDevice;

/**
 * MikroTik RouterOS REST API (RouterOS 7.1+) over HTTPS: `https://<management_ip>:<port>/rest`, HTTP Basic.
 *
 * Every call needs the installation switch (services.routeros.enabled); with it off nothing is sent, reads
 * included. The router certificate is always verified, against ROUTEROS_CA_BUNDLE or the system store,
 * and redirects are never followed. Failures are a RouterOsException; the router's answer is never echoed
 * beyond the RouterOS error message.
 *
 * Service type `pppoe` uses /ppp/secret and /ppp/active; `hotspot` uses /ip/hotspot/user and
 * /ip/hotspot/active. RouterOS returns every value as a string; the shapes below are already converted.
 */
class RouterOsClient
{
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 10;

    /** @var array<string, array{accounts: string, sessions: string, login: string}> */
    private const MENUS = [
        'pppoe' => ['accounts' => 'ppp/secret', 'sessions' => 'ppp/active', 'login' => 'name'],
        'hotspot' => ['accounts' => 'ip/hotspot/user', 'sessions' => 'ip/hotspot/active', 'login' => 'user'],
    ];

    private const ACCOUNT_FIELDS = '.id,name,profile,disabled,comment';

    /** PPP secrets also say which service they serve (pppoe, l2tp, ...): adoption must never take over a VPN user. */
    private const PPP_EXTRA_FIELDS = ',service';

    /** cURL errors that mean the certificate or the TLS handshake, not the network. */
    private const TLS_FAILURE = '/cURL error (35|51|53|54|58|59|60|64|66|77|80|82|83|90|91|98):|SSL|certificate/i';

    /** @return array{version: string, board_name: string, uptime: string} */
    public function systemResource(NasDevice $nas): array
    {
        $resource = $this->send($nas, 'GET', 'system/resource');

        if (! is_array($resource) || array_is_list($resource)) {
            throw RouterOsException::server('The router answered with an unexpected system resource.');
        }

        return [
            'version' => (string) ($resource['version'] ?? ''),
            'board_name' => (string) ($resource['board-name'] ?? ''),
            'uptime' => (string) ($resource['uptime'] ?? ''),
        ];
    }

    /** @return array{id: string, name: string, profile: ?string, disabled: bool, comment: ?string, service: ?string}|null */
    public function find(NasDevice $nas, string $type, string $name): ?array
    {
        $rows = $this->rows($this->send($nas, 'GET', self::MENUS[$type]['accounts'], ['name' => $name, '.proplist' => $this->accountFields($type)]));

        return $rows === [] ? null : $this->account($rows[0]);
    }

    /**
     * @param  array<string, string|bool>  $fields
     * @return string the new record id
     */
    public function create(NasDevice $nas, string $type, array $fields): string
    {
        $created = $this->send($nas, 'PUT', self::MENUS[$type]['accounts'], body: $this->strings($fields));

        return $this->recordId(is_array($created) ? ($created['.id'] ?? null) : null);
    }

    /** @param  array<string, string|bool>  $fields */
    public function update(NasDevice $nas, string $type, string $id, array $fields): void
    {
        $this->send($nas, 'PATCH', self::MENUS[$type]['accounts'].'/'.$this->recordId($id), body: $this->strings($fields));
    }

    /** A record that is already gone counts as removed. */
    public function remove(NasDevice $nas, string $type, string $id): void
    {
        $this->send($nas, 'DELETE', self::MENUS[$type]['accounts'].'/'.$this->recordId($id), notFoundIsFine: true);
    }

    /** @return list<array{id: string, name: string, address: ?string}> */
    public function activeSessions(NasDevice $nas, string $type, ?string $name = null): array
    {
        $login = self::MENUS[$type]['login'];
        $query = ($name === null ? [] : [$login => $name]) + ['.proplist' => ".id,{$login},address"];

        return array_map(
            fn (array $row): array => ['id' => (string) ($row['.id'] ?? ''), 'name' => (string) ($row[$login] ?? ''), 'address' => $this->text($row['address'] ?? null)],
            $this->rows($this->send($nas, 'GET', self::MENUS[$type]['sessions'], $query)),
        );
    }

    /** Ends one session. A session that is already gone counts as ended. */
    public function disconnect(NasDevice $nas, string $type, string $activeId): void
    {
        $this->send($nas, 'DELETE', self::MENUS[$type]['sessions'].'/'.$this->recordId($activeId), notFoundIsFine: true);
    }

    /** @return list<array{id: string, name: string, profile: ?string, disabled: bool, comment: ?string, service: ?string}> */
    public function listAccounts(NasDevice $nas, string $type): array
    {
        return array_map($this->account(...), $this->rows($this->send($nas, 'GET', self::MENUS[$type]['accounts'], ['.proplist' => $this->accountFields($type)])));
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>|null  $body
     */
    private function send(NasDevice $nas, string $method, string $path, array $query = [], ?array $body = null, bool $notFoundIsFine = false): mixed
    {
        try {
            return $this->perform($nas, $method, $path, $query, $body, $notFoundIsFine);
        } catch (RouterOsException $e) {
            $e->nasDeviceId = $nas->id;

            throw $e;
        }
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>|null  $body
     */
    private function perform(NasDevice $nas, string $method, string $path, array $query = [], ?array $body = null, bool $notFoundIsFine = false): mixed
    {
        if (! config('services.routeros.enabled')) {
            throw RouterOsException::disabled();
        }

        $request = Http::withBasicAuth($nas->api_username, $nas->api_password)
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->withoutRedirecting()
            ->withOptions(['verify' => config('services.routeros.ca_bundle') ?: true]);

        $url = $this->baseUrl($nas).'/'.$path;

        try {
            $response = match ($method) {
                'GET' => $request->get($url, $query),
                'PUT' => $request->put($url, $body),
                'PATCH' => $request->patch($url, $body),
                'DELETE' => $request->delete($url),
                default => throw new \InvalidArgumentException("Unsupported method {$method}"),
            };
        } catch (ConnectionException $e) {
            throw preg_match(self::TLS_FAILURE, $e->getMessage()) ? RouterOsException::tls() : RouterOsException::unreachable();
        }

        if ($response->status() === 404 && $notFoundIsFine) {
            return null;
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $json = $response->json();

        if ($response->body() !== '' && $json === null) {
            throw RouterOsException::server('The router answered with something that is not RouterOS JSON.', $response->status());
        }

        return $json;
    }

    private function baseUrl(NasDevice $nas): string
    {
        $host = $nas->networkAsset?->getAttribute('management_ip');

        if (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw RouterOsException::rejected('The router asset has no valid management IP.');
        }

        $host = str_contains($host, ':') ? "[{$host}]" : $host;

        return "https://{$host}:{$nas->api_port}/rest";
    }

    private function failure(Response $response): RouterOsException
    {
        $status = $response->status();

        return match (true) {
            in_array($status, [401, 403], true) => RouterOsException::auth($status),
            $status >= 500 || ($status >= 300 && $status < 400) => RouterOsException::server("The router answered with an unexpected response (HTTP {$status}).", $status),
            default => RouterOsException::rejected($this->routerMessage($response) ?? "The router rejected the request (HTTP {$status}).", $status),
        };
    }

    /** The RouterOS error text: `message` plus `detail` of a JSON error body, nothing else. */
    private function routerMessage(Response $response): ?string
    {
        $json = $response->json();

        if (! is_array($json) || ! is_string($json['message'] ?? null)) {
            return null;
        }

        $text = trim($json['message'].(is_string($json['detail'] ?? null) ? ': '.$json['detail'] : ''));

        return Str::limit(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? ''), 255, '');
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $json): array
    {
        if (! is_array($json) || ($json !== [] && ! array_is_list($json))) {
            throw RouterOsException::server('The router answered with an unexpected list.');
        }

        return array_values(array_filter($json, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, name: string, profile: ?string, disabled: bool, comment: ?string, service: ?string}
     */
    private function account(array $row): array
    {
        return [
            'id' => (string) ($row['.id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'profile' => $this->text($row['profile'] ?? null),
            'disabled' => ($row['disabled'] ?? 'false') === 'true',
            'comment' => $this->text($row['comment'] ?? null),
            'service' => $this->text($row['service'] ?? null),
        ];
    }

    private function accountFields(string $type): string
    {
        return self::ACCOUNT_FIELDS.($type === 'pppoe' ? self::PPP_EXTRA_FIELDS : '');
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** RouterOS record ids look like `*1A`; anything else is never put into a URL. */
    private function recordId(mixed $id): string
    {
        if (! is_string($id) || ! preg_match('/^\*[0-9A-Fa-f]+$/', $id)) {
            throw RouterOsException::server('The router returned an unexpected record id.');
        }

        return $id;
    }

    /**
     * RouterOS reads every value as a string.
     *
     * @param  array<string, string|bool>  $fields
     * @return array<string, string>
     */
    private function strings(array $fields): array
    {
        return array_map(fn (string|bool $value): string => is_bool($value) ? ($value ? 'true' : 'false') : $value, $fields);
    }
}
