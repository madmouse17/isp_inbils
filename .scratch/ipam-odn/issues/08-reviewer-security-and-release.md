# REVIEWER: security review, dependency audit and release readiness

Status: ready-for-agent
Role: reviewer
Blocked by: 07
Card: none (new lane; no Hermes card)

## Scope

- Security pass (rule 10: new permissions and user data): every `/admin/ipam/*` and `/admin/odn/*` route has a policy or permission check and company scoping; Form Requests use company-scoped `exists` for every id; no raw SQL with user input (CIDR and address strings only reach the DB after ip-lib normalisation, through bindings); no new `/api/*` routes; asset write-through cannot overwrite another holder's address; onboarding with a foreign `odn_port_id` writes nothing.
- Dependency audit (rule 11): `composer audit` and `npm audit` output pasted; every advisory reported as package, CVE, severity, affected vs fixed version and update command. Record `mlocati/ip-lib` installed version and licence.
- Cross-lane check: `SubscriptionService.php`, `RolePermissionSeeder.php`, `navigation.ts` and `Modules/NetworkAsset/routes/web.php` contain both this lane's and the MikroTik/RADIUS lane's changes intact if that lane landed first.
- Release notes: `composer install`, `php artisan migrate`, `php artisan db:seed --class=RolePermissionSeeder --force`, `php artisan ipam:import-asset-ips --dry-run` per company, then the real run; review duplicates with the owner before the real run.
- Rollback: migrations down in reverse order (`ip_addresses`, `ip_prefixes`, `vlans`, `odn_ports`/`odn_splitters`); precondition — export the tables first, rollback loses the registers; remove `mlocati/ip-lib` and the `Ipam` module entry.
- Ledger entry added to `docs/plans/INBILS_OMP_BACKLOG.md` and `.scratch` statuses reconciled.

## Acceptance criteria

- [ ] Verdict APPROVED or BLOCKED with file:line findings.
- [ ] Audit output pasted with every advisory listed (or "0 advisories").
- [ ] Release notes and rollback precondition recorded.

## Comments
