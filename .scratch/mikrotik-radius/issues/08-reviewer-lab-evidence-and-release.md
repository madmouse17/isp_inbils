# REVIEWER: lab evidence on a CHR router, release readiness, rollback rehearsal

Status: ready-for-agent
Role: reviewer
Blocked by: 07
Card: none (new lane; no Hermes card)

## Scope

- Lab run (satisfies the trigger in `docs/plans/ENTERPRISE_ISP_EXECUTION_PLAN.md:482`): a MikroTik CHR VM (RouterOS 7.x, Free licence) prepared exactly by the runbook in `docs/business/network.md` (API user group, `www-ssl` certificate from a lab CA in `ROUTEROS_CA_BUNDLE`, PPP profiles named like the seeded speed profiles, isolir profile with address list and redirect to `/isolir/{company code}`), plus a PPPoE client (a second CHR or a Linux `pppoe` client). Inbils on Sail with `ROUTEROS_PROVISIONING_ENABLED=true`.
- Walk the flow and capture evidence (router CLI output and Inbils screens, credentials and addresses redacted): register the NAS router with writes off → check (version shown) → unmanaged list shows a hand-made secret → adopt it through a network account → writes on → pending subscription: secret enabled and the client connects → activate (no router change) → package change: new profile, session dropped, client reconnects → suspend from Receivables: isolir profile, session dropped, the client's HTTP request lands on the isolir page → manual payment of the overdue invoice: automatic reconnection, speed profile back → stop the CHR: account `pending`, one notification, other Inbils actions unaffected → start the CHR: next `network:sync` catches up → terminate: secret removed. Repeat `network:sync` with no change: zero router writes.
- Release readiness: docs and CHANGELOG updated (ticket 04), deploy steps and the rollback order from `../spec.md` (Further Notes) rehearsed on the lab (write flag off, installation switch off, migrations down on `inbils_test` only), ledger entry drafted for the orchestrator, `.scratch` statuses reconciled.

Owned files: none (evidence goes into `## Comments` of this ticket; the ledger is updated by the orchestrator).

## Acceptance criteria

- [ ] Verdict APPROVED or BLOCKED with `file:line` findings.
- [ ] Lab evidence for every step above, without credentials, router addresses or subscriber passwords.
- [ ] Rollback rehearsal recorded, including the warning that dropping `network_accounts` destroys stored subscriber passwords.
- [ ] Recommendation for the first production router (maintenance window, writes-off onboarding, number of subscribers adopted) for the owner to approve.

## Comments
