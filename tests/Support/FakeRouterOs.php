<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * An in-memory MikroTik router answering the RouterOS REST API (RouterOS 7.x shapes: every value a string,
 * `.id` like `*1A`, errors as {"error":..,"message":..,"detail":..}). One instance per host and port.
 * Fake credentials and documentation-range addresses only.
 *
 * Used through Tests\Traits\InteractsWithRouterOs; tests inspect `accounts()`, `sessions()` and `requests()`.
 */
final class FakeRouterOs
{
    /** @var array<string, array{accounts: string, sessions: string, login: string}> */
    private const MENUS = [
        'pppoe' => ['accounts' => 'ppp/secret', 'sessions' => 'ppp/active', 'login' => 'name'],
        'hotspot' => ['accounts' => 'ip/hotspot/user', 'sessions' => 'ip/hotspot/active', 'login' => 'user'],
    ];

    /** @var array<string, list<string>> profile names per service type */
    private array $profiles;

    /** @var array<string, array<string, array<string, string>>> accounts per service type, keyed by record id */
    private array $accounts = ['pppoe' => [], 'hotspot' => []];

    /** @var array<string, array<string, array<string, string>>> */
    private array $sessions = ['pppoe' => [], 'hotspot' => []];

    /** @var list<array{method: string, path: string, query: array<string, string>, body: ?array<string, mixed>, verify: mixed, allow_redirects: mixed, connect_timeout: mixed, timeout: mixed, auth_user: ?string, has_password: bool}> */
    private array $requests = [];

    private ?string $failure = null;

    /** @var list<array<string, mixed>> */
    private array $connections = [];

    /** @var list<array{command: string, words: list<string>}> */
    private array $commands = [];

    private string $failureDetail = '';

    /** @var list<string> request path prefixes the current failure is limited to (empty = every request) */
    private array $failurePaths = [];

    private int $nextId = 1;

    /** @var array<string, string> */
    public array $resource = ['version' => '7.16.2 (stable)', 'board-name' => 'CHR', 'uptime' => '3d4h5m'];

    /** @param  list<string>  $profiles */
    public function __construct(public readonly string $host, public readonly int $port = 443, array $profiles = [])
    {
        $this->profiles = ['pppoe' => ['default', ...$profiles], 'hotspot' => ['default', ...$profiles]];
    }

    /** Create a profile on the router (the NOC does this by hand in real life). */
    public function profile(string $name, string ...$more): self
    {
        foreach (['pppoe', 'hotspot'] as $type) {
            $this->profiles[$type] = array_values(array_unique([...$this->profiles[$type], $name, ...$more]));
        }

        return $this;
    }

    /** @param  array<string, string>  $extra */
    public function seedAccount(string $type, string $name, string $profile = 'default', bool $disabled = false, string $password = 'fake-seeded-secret', string $comment = '', array $extra = []): string
    {
        $id = $this->newId();
        $row = ['.id' => $id, 'name' => $name, 'password' => $password, 'profile' => $profile, 'disabled' => $disabled ? 'true' : 'false', 'comment' => $comment];

        if ($type === 'pppoe') {
            $row['service'] = 'pppoe';
        }

        $this->accounts[$type][$id] = $extra + $row;

        return $id;
    }

    public function seedSession(string $type, string $name, string $address = '10.10.0.2'): string
    {
        $id = '*A'.dechex($this->nextId++);
        $this->sessions[$type][$id] = ['.id' => $id, self::MENUS[$type]['login'] => $name, 'address' => $address, 'uptime' => '1h2m'];

        return $id;
    }

    /** @return array<string, string>|null the stored record, password included (the router keeps it) */
    public function account(string $type, string $name): ?array
    {
        foreach ($this->accounts[$type] as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        return null;
    }

    /** The NOC edits a record by hand (no request is logged, no validation happens). @param  array<string, string>  $changes */
    public function changeAccount(string $type, string $name, array $changes): void
    {
        foreach ($this->accounts[$type] as $id => $row) {
            if ($row['name'] === $name) {
                $this->accounts[$type][$id] = $changes + $row;
            }
        }
    }

    /** The NOC deletes a record by hand. */
    public function removeAccount(string $type, string $name): void
    {
        foreach ($this->accounts[$type] as $id => $row) {
            if ($row['name'] === $name) {
                unset($this->accounts[$type][$id]);
            }
        }
    }

    /** @return list<array<string, string>> */
    public function accounts(string $type = 'pppoe'): array
    {
        return array_values($this->accounts[$type]);
    }

    /** @return list<array<string, string>> the active sessions, optionally of one login */
    public function sessions(string $type = 'pppoe', ?string $name = null): array
    {
        $login = self::MENUS[$type]['login'];

        return array_values(array_filter($this->sessions[$type], fn (array $row): bool => $name === null || $row[$login] === $name));
    }

    /**
     * Make every request fail from now on: refused | timeout | tls | auth | forbidden | rejected | server | redirect | garbage.
     * `rejected` answers 400 with the given RouterOS detail.
     */
    public function down(string $failure, string $detail = 'input does not match any value of profile'): self
    {
        $this->failure = $failure;
        $this->failurePaths = [];
        $this->failureDetail = $detail;

        return $this;
    }

    /**
     * Fail only the requests whose path starts with one of the prefixes (e.g. `ppp/active`); the rest of the router
     * keeps answering. This is a failure between two calls of one operation.
     *
     * @param  list<string>  $pathPrefixes
     */
    public function downOn(array $pathPrefixes, string $failure = 'refused'): self
    {
        $this->failure = $failure;
        $this->failurePaths = $pathPrefixes;

        return $this;
    }

    /** The failure set by down()/downOn(), null while the router answers. */
    public function failure(): ?string
    {
        return $this->failure;
    }

    public function failureDetail(): string
    {
        return $this->failureDetail;
    }

    public function hasProfile(string $type, string $profile): bool
    {
        return in_array($profile, $this->profiles[$type], true);
    }

    /** The stored record of an id, password included. @return array<string, string>|null */
    public function accountRow(string $type, string $id): ?array
    {
        return $this->accounts[$type][$id] ?? null;
    }

    /** @param  array<string, string>  $changes */
    public function patchAccount(string $type, string $id, array $changes): void
    {
        $this->accounts[$type][$id] = $changes + $this->accounts[$type][$id];
    }

    /** @return array<string, array<string, string>> */
    public function accountsById(string $type): array
    {
        return $this->accounts[$type];
    }

    /** @return array<string, array<string, string>> */
    public function sessionsById(string $type): array
    {
        return $this->sessions[$type];
    }

    public function forgetAccount(string $type, string $id): bool
    {
        $found = isset($this->accounts[$type][$id]);
        unset($this->accounts[$type][$id]);

        return $found;
    }

    public function forgetSession(string $type, string $id): bool
    {
        $found = isset($this->sessions[$type][$id]);
        unset($this->sessions[$type][$id]);

        return $found;
    }

    /** Binary API: one entry per connection opened (the client config, credentials included: fake ones). @param  array<string, mixed>  $config */
    public function recordConnection(array $config): void
    {
        $this->connections[] = $config;
    }

    /** @return list<array<string, mixed>> */
    public function connections(): array
    {
        return $this->connections;
    }

    /** @param  list<string>  $words */
    public function recordCommand(string $command, array $words): void
    {
        $this->commands[] = ['command' => $command, 'words' => $words];
    }

    /** Binary API: every command sent. @return list<array{command: string, words: list<string>}> */
    public function commands(): array
    {
        return $this->commands;
    }

    public function up(): self
    {
        $this->failure = null;
        $this->failurePaths = [];

        return $this;
    }

    /** @return list<array{method: string, path: string, query: array<string, string>, body: ?array<string, mixed>, verify: mixed, allow_redirects: mixed, connect_timeout: mixed, timeout: mixed, auth_user: ?string, has_password: bool}> */
    public function requests(): array
    {
        return $this->requests;
    }

    /** @return list<array{method: string, path: string, query: array<string, string>, body: ?array<string, mixed>, verify: mixed, allow_redirects: mixed, connect_timeout: mixed, timeout: mixed, auth_user: ?string, has_password: bool}> */
    public function writes(): array
    {
        return array_values(array_filter($this->requests, fn (array $request): bool => $request['method'] !== 'GET'));
    }

    public function forgetRequests(): self
    {
        $this->requests = [];

        return $this;
    }

    /** @param  array<string, mixed>  $options Guzzle options of the call */
    public function handle(Request $request, array $options): mixed
    {
        $uri = $request->toPsrRequest()->getUri();
        $path = ltrim(preg_replace('#^/rest/?#', '', $uri->getPath()) ?? '', '/');
        $query = $this->query($uri->getQuery());
        $body = json_decode((string) $request->toPsrRequest()->getBody(), true);
        $body = is_array($body) ? $body : null;

        $this->requests[] = [
            'method' => $request->method(),
            'path' => $path,
            'query' => $query,
            'body' => $body,
            'verify' => $options['verify'] ?? null,
            'allow_redirects' => $options['allow_redirects'] ?? null,
            'connect_timeout' => $options['connect_timeout'] ?? null,
            'timeout' => $options['timeout'] ?? null,
            'auth_user' => $options['auth'][0] ?? null,
            'has_password' => ($options['auth'][1] ?? '') !== '',
        ];

        return $this->failure !== null && $this->failureApplies($path)
            ? $this->failed($request)
            : $this->route($request->method(), $path, $query, $body ?? []);
    }

    public function failureApplies(string $path): bool
    {
        if ($this->failurePaths === []) {
            return true;
        }

        foreach ($this->failurePaths as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, string>  $query */
    private function route(string $method, string $path, array $query, array $body): mixed
    {
        if ($path === 'system/resource' && $method === 'GET') {
            return $this->json($this->resource);
        }

        foreach (self::MENUS as $type => $menu) {
            if ($path === $menu['accounts']) {
                return match ($method) {
                    'GET' => $this->json($this->select($this->accounts[$type], $query, ['password'])),
                    'PUT' => $this->create($type, $body),
                    default => $this->notFound(),
                };
            }

            if (preg_match('#^'.preg_quote($menu['accounts'], '#').'/(\*[0-9A-Fa-f]+)$#', $path, $match)) {
                return $this->accountById($type, $method, $match[1], $body);
            }

            if ($path === $menu['sessions'] && $method === 'GET') {
                return $this->json($this->select($this->sessions[$type], $query));
            }

            if (preg_match('#^'.preg_quote($menu['sessions'], '#').'/(\*[0-9A-Fa-f]+)$#', $path, $match) && $method === 'DELETE') {
                if (! isset($this->sessions[$type][$match[1]])) {
                    return $this->notFound();
                }
                unset($this->sessions[$type][$match[1]]);

                return Http::response('', 204);
            }
        }

        return $this->notFound();
    }

    /** @param  array<string, mixed>  $body */
    private function create(string $type, array $body): mixed
    {
        $name = $body['name'] ?? null;

        if (! is_string($name) || $name === '') {
            return $this->error(400, 'Bad Request', 'failure: name must be set');
        }

        if ($this->account($type, $name) !== null) {
            return $this->error(400, 'Bad Request', 'failure: secret with the same name already exists');
        }

        if (($rejected = $this->unknownProfile($type, $body)) !== null) {
            return $rejected;
        }

        $id = $this->seedAccount($type, $name, (string) ($body['profile'] ?? 'default'), ($body['disabled'] ?? 'false') === 'true', (string) ($body['password'] ?? ''), (string) ($body['comment'] ?? ''));

        return $this->json($this->accounts[$type][$id]);
    }

    /** @param  array<string, mixed>  $body */
    private function accountById(string $type, string $method, string $id, array $body): mixed
    {
        if (! isset($this->accounts[$type][$id])) {
            return $this->notFound();
        }

        if ($method === 'DELETE') {
            unset($this->accounts[$type][$id]);

            return Http::response('', 204);
        }

        if ($method !== 'PATCH') {
            return $this->notFound();
        }

        if (($rejected = $this->unknownProfile($type, $body)) !== null) {
            return $rejected;
        }

        foreach ($body as $key => $value) {
            $this->accounts[$type][$id][$key] = (string) $value;
        }

        return $this->json($this->accounts[$type][$id]);
    }

    /** @param  array<string, mixed>  $body */
    private function unknownProfile(string $type, array $body): mixed
    {
        return isset($body['profile']) && ! in_array($body['profile'], $this->profiles[$type], true)
            ? $this->error(400, 'Bad Request', 'input does not match any value of profile')
            : null;
    }

    /**
     * Rows filtered by the non-dot query keys and cut down to `.proplist`, like a RouterOS print.
     *
     * @param  array<string, array<string, string>>  $rows
     * @param  array<string, string>  $query
     * @param  list<string>  $hidden
     * @return list<array<string, string>>
     */
    public function select(array $rows, array $query, array $hidden = []): array
    {
        $filters = array_filter($query, fn (string $key): bool => $key[0] !== '.', ARRAY_FILTER_USE_KEY);
        $proplist = isset($query['.proplist']) ? explode(',', $query['.proplist']) : null;
        $selected = [];

        foreach ($rows as $row) {
            foreach ($filters as $key => $value) {
                if (($row[$key] ?? null) !== $value) {
                    continue 2;
                }
            }

            $row = $proplist === null ? array_diff_key($row, array_flip($hidden)) : array_intersect_key($row, array_flip($proplist));
            $selected[] = $row;
        }

        return $selected;
    }

    private function failed(Request $request): mixed
    {
        return match ($this->failure) {
            'refused' => $this->connectionFailure($request, 'cURL error 7: Failed to connect to '.$this->host.' port '.$this->port.' after 2 ms: Connection refused'),
            'timeout' => $this->connectionFailure($request, 'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received'),
            'tls' => $this->connectionFailure($request, 'cURL error 60: SSL certificate problem: unable to get local issuer certificate'),
            'auth' => $this->error(401, 'Unauthorized', null),
            'forbidden' => $this->error(403, 'Forbidden', null),
            'rejected' => $this->error(400, 'Bad Request', $this->failureDetail),
            'server' => Http::response('<html><body>Internal error with fake-leaked-secret-123</body></html>', 500),
            'redirect' => Http::response('', 302, ['Location' => 'https://198.51.100.9/rest/system/resource']),
            'garbage' => Http::response('<html><body>not routeros</body></html>', 200),
        };
    }

    private function connectionFailure(Request $request, string $message): PromiseInterface
    {
        return (Http::failedConnection($message))($request);
    }

    private function notFound(): mixed
    {
        return $this->error(404, 'Not Found', null);
    }

    private function error(int $status, string $message, ?string $detail): mixed
    {
        return Http::response(array_filter(['error' => $status, 'message' => $message, 'detail' => $detail], fn ($value) => $value !== null), $status);
    }

    /** @param  array<int|string, mixed>  $payload */
    private function json(array $payload): mixed
    {
        return Http::response($payload, 200);
    }

    public function newId(): string
    {
        return '*'.strtoupper(dechex($this->nextId++));
    }

    /** `parse_str` would turn `.proplist` into `_proplist`, so the query is split by hand. */
    private function query(string $query): array
    {
        $parsed = [];

        foreach (array_filter(explode('&', $query)) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parsed[rawurldecode($key)] = rawurldecode($value);
        }

        return $parsed;
    }
}
