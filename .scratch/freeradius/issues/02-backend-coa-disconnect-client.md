# BACKEND: RFC 5176 Disconnect-Request sender (no dependency)

Status: ready-for-agent
Role: backend
Blocked by: none
Card: none (new lane; no Hermes card)

## Scope

`../spec.md` Q7 and Q8 (b): our own Disconnect-Request sender, about 80 lines, no Composer package, no `ext-sockets`, no `radclient`.

- `Modules\NetworkAsset\Services\Radius\RadiusDisconnectClient`:
  - `disconnect(string $host, int $port, string $secret, array $attributes): DisconnectResult` with `$attributes` keys `User-Name`, `Acct-Session-Id`, `NAS-IP-Address` (strings; IPv4 for `NAS-IP-Address`).
  - Pure `encode(int $identifier, array $attributes, string $secret): string`: code 40, identifier, length, Request Authenticator = MD5(code + identifier + length + 16 zero octets + attributes + secret) (RFC 5176 §2.3, same as accounting), attributes 1 (User-Name), 44 (Acct-Session-Id), 4 (NAS-IP-Address).
  - Pure `decode(string $packet, string $requestAuthenticator, string $secret): DisconnectResult`: accepts code 41 (ACK) or 42 (NAK) only when identifier matches and Response Authenticator = MD5(code + id + length + request authenticator + attributes + secret); parses `Error-Cause` (101, 4-octet integer) on NAK; anything else = `invalid`.
  - Send over `stream_socket_client("udp://{$host}:{$port}")`, IPv6 bracketed, read timeout `services.radius.coa_timeout`, one attempt (the job retries). Refuses with `RadiusException` kind `disabled` when `services.radius.enabled` is false (throws before opening a socket). Identifier from `random_int(0, 255)`.
  - Never logs packets, attributes or the secret; error messages name only host:port.
- `DisconnectResult` (readonly): `status` `ack|nak|timeout|invalid`, `errorCause` int|null; helper `isDone()` = `ack` or `nak` with cause 503 (Session-Context-Not-Found).
- Test fakes for later tickets: `tests/Support/FakeDisconnectClient.php` (replaces the client via the container; records every call with host, port, attributes and whether a secret was given, never the secret; per-address scripted answer `ack|nak:<cause>|timeout`) and `tests/Traits/InteractsWithNasCoa.php` (binds the fake, helpers `coaAnswers(string $host, string $answer)`, `disconnectsSent()`).

Owned files: NEW `Modules/NetworkAsset/app/Services/Radius/RadiusDisconnectClient.php`, NEW `Modules/NetworkAsset/app/Services/Radius/DisconnectResult.php`, NEW `tests/Support/FakeDisconnectClient.php`, NEW `tests/Traits/InteractsWithNasCoa.php`, NEW `tests/Unit/RadiusDisconnectClientTest.php`.

Consumes: `RadiusException` (ticket 01; if 01 has not landed, message the ticket 01 agent for the class name, do not create a second exception).
Produces: `RadiusDisconnectClient::disconnect(...)`, `DisconnectResult`, the fake and trait.

## Acceptance criteria

- [ ] `encode` matches a fixed byte vector computed independently (document how in the test, e.g. produced once with the lab `radclient` and a fake secret, never the lab secret).
- [ ] `decode`: ACK accepted; NAK with `Error-Cause` 503 parsed and `isDone()` true; NAK 404/other not done; wrong identifier, wrong authenticator (forged reply) or unknown code → `invalid`.
- [ ] Timeout: sending to a local UDP socket that never answers returns `timeout` within the configured timeout (+1 s).
- [ ] Switch off → `RadiusException` `disabled`, no socket opened.
- [ ] Wire proof is deferred to ticket 06 (nas-sim verifies the authenticator, `~/labs/freeradius/nas-sim/app.py:207-210`).

## Commands

Sail runs on docker context `default`: prefix every sail call with `DOCKER_CONTEXT=default`; never `sail up`, never the desktop-linux context.

```
DOCKER_CONTEXT=default ./vendor/bin/sail exec laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact tests/Unit/RadiusDisconnectClientTest.php
```

## Comments
