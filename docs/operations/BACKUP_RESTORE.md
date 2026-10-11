# Backup and restore operations

Status: implementation guide only. The proposed RPO 24h / RTO 4h, daily backups, 30-day retention, and monthly drill cadence remain provisional until the owner explicitly approves them.

## Off-host evidence contract

**EXTERNAL_EVIDENCE_REQUIRED** — All script outputs end with `EXTERNAL_EVIDENCE_REQUIRED` to indicate that off-host archive delivery and restore success
have NOT been verified by automation. An operator must:
1. Confirm the archive reached the off-host destination (provider retrieval, checksum match).
2. Record the verification in the drill template or operations log.
3. Until that step is complete, the backup/restore is **not** proven.

The scripts never claim off-host success. Off-host provider configuration, credentials, and scheduling remain an explicit human/operator blocker.

## Contract

- Backup both MySQL and required media assets.
- Write encrypted archives to an explicit off-host destination.
- Read credentials only from deployment-provided secret material; do not source `.env` or hardcode secrets.
- Require explicit source and destination arguments.
- Refuse blank paths, protected locations, pre-existing backup outputs, and any restore that would overwrite production or a non-empty target.
- Emit checksums and a manifest for every backup set.
- Keep retention pruning deterministic and explicit.
- Support stale/failure alert hooks from the caller (configured via `OPS_ALERT_HOOK` env var; secrets stay in the operator environment, never in these scripts).
- Restore into an isolated empty target, then prove cleanup of temporary restore artifacts.
- Verify the SHA-256 manifest before applying any dump; fail closed on mismatch.

## Scripts

- `scripts/operations/backup.sh`
- `scripts/operations/restore.sh`
- `scripts/operations/restore-drill.sh`
- `scripts/operations/backup.py`
- `scripts/operations/restore.py`
- `scripts/operations/verify-backup-restore.py`

## Required inputs

Provide these from the deployment secret store or equivalent runtime secret injection:

- `BACKUP_DB_HOST`
- `BACKUP_DB_PORT`
- `BACKUP_DB_USER`
- `BACKUP_DB_PASSWORD_FILE`
- `BACKUP_DB_NAME`
- `BACKUP_MEDIA_DIR`
- `BACKUP_DEST_DIR`
- `BACKUP_ENCRYPTION_KEY_FILE`

The scripts do not read secrets from repo files or `.env`. Credentials are passed to the MySQL client via a `defaults-extra-file` (preferred, mode 600) or environment variables; passwords are never logged.

`BACKUP_MEDIA_DIR` must cover `storage/app/private` (ticket attachments, SPK evidence, customer documents); `storage/app/public` only holds the company logo. Pointing it at `storage/app` covers both.

## Safety rules

- Never point backup output at the source tree.
- Never restore into an existing populated directory.
- Never restore a dump into the source database name.
- Never skip checksum generation.
- Never skip checksum verification before restore.
- Never keep temporary restore worktrees or scratch directories after a successful drill.
- Never embed secrets in alert hook configuration; pass `OPS_ALERT_HOOK` from the operator environment.

## Alert hook

Backup/restore failures invoke a configurable monitored alert hook. Set `OPS_ALERT_HOOK` in the operator environment — a shell command string that receives `<event> <message>`. Secrets (e.g. webhook URL) live only in the operator environment, never in these scripts.

```bash
# Example: curl a webhook (secret URL comes from env, not repo)
export OPS_ALERT_HOOK='curl -fsS -m 10 -d "event=$1&message=$2" "$MY_WEBHOOK_URL"'
```

When the hook is configured and delivery fails, the script reports the delivery failure honestly and still exits non-zero. When the hook is not configured, the failure is reported directly without a delivery attempt.

## Verification

Run the non-destructive self-check first:

```bash
python scripts/operations/verify-backup-restore.py --self-check
```

Or use the shell scripts directly:

```bash
bash scripts/operations/backup.sh --database inbils_test --output-dir ./test-backup-output
bash scripts/operations/restore.sh --input <dump.sql> --checksum-file <dump.sql>.sha256 --database inbils_restore_drill_<ts> --create-database
```

## Backup freshness marker

After every verified backup, stamp the backup freshness marker so `/ready` can report backup status:

```bash
php artisan ops:backup-heartbeat
```

The marker is a cache key read by `ReadinessChecker::backup()`. If the marker is older than 25 hours, `/ready` reports backup `fail` (degraded). If the marker was never set, it reports `unknown`. This is consumed by the production readiness check only — the backup script itself is independent. The backup script's output includes `EXTERNAL_EVIDENCE_REQUIRED` until the operator verifies the archive reached the off-host destination.

For a full drill, use `scripts/operations/restore-drill.sh` which chains backup + restore.
