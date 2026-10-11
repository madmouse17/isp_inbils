# BACKEND: RouterOS 6 API fallback (binary API on 8728/8729)

Status: needs-owner-approval
Role: backend
Blocked by: owner approval of a RouterOS 6 library (`../spec.md` Q3b), tickets 02-04 shipped
Card: none (new lane; no Hermes card)

## Why this ticket exists

The owner approved Q3 on 2026-10-12 as "RouterOS 7.1+ REST first" because the fleet versions are not known yet. The REST client (`Modules/NetworkAsset/app/Services/RouterOsClient.php`) cannot talk to a router that stays on RouterOS 6: the REST API does not exist there. If the version check in the lab (ticket 08) or the first `Check now` shows a RouterOS 6 router, this ticket decides how to serve it. Nothing here starts before the owner answers.

## Owner decision needed

1. Does any NAS router have to stay on RouterOS 6? (The router page shows `routeros_version` after the first check; a probe of a v6 router fails with `unreachable`/`server`, never with data.)
2. If yes, pick one:
   (a) `evilfreelancer/routeros-api-php` 1.7.1 (MIT, RouterOS v6.43+ and v7, binary API on 8728/8729). Needs `ext-sockets`, which the production image does not install (`Dockerfile:32-42`), and pulls in `spatie/ssh`. Allowed by the owner in principle ("well-maintained standard libraries"); needs the library name, version and reason in the ticket comment and a `composer audit` run.
   (b) Upgrade the router to RouterOS 7.1 or later (no Inbils work).
   (c) Leave RouterOS 6 subscribers manual (no Inbils work).
   **Recommend (b)** for a lab pilot; (a) only for a router that cannot be upgraded.

## Scope if (a) is approved

- A second transport behind the existing operations of `RouterOsClient` (`systemResource`, `find`, `create`, `update`, `remove`, `activeSessions`, `disconnect`, `listAccounts`): the provisioner, the jobs and the sync keep calling the same methods. Choose the transport per NAS router by a nullable column `nas_devices.api_transport` (`rest|binary`, default `rest`), set on the router form; a router never mixes transports.
- `ext-sockets` in the production image (`Dockerfile`), a TLS API port (8729) with a verified certificate like the REST client (no skip-verification switch), the same installation switch, write flag, redirect-free and timeout rules.
- Same error kinds (`unreachable|tls|auth|rejected|server`), same 255-character message cap, no router output echoed beyond the RouterOS `!trap` message.
- Test double: an in-memory binary-API fake next to `tests/Support/FakeRouterOs.php`, behind the same `InteractsWithRouterOs` helpers; no real socket in CI.
- Docs: `docs/business/network.md` runbook gains the v6 API service and user-group policies.

Non-goals: RADIUS, new subscriber features, changing the desired-state rules or the apply algorithm.

## Acceptance criteria (only after approval)

- [ ] A RouterOS 6 lab router (CHR 6.49) passes the same flow matrix as RouterOS 7 in tickets 03 and 06 through the binary transport.
- [ ] `composer audit` pasted (rule 11) with the new package; library, version and reason stated.
- [ ] Pint and Larastan clean; `tests/Feature/NetworkAsset` green.

## Comments
