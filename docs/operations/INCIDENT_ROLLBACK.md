# Incident rollback runbook

This document covers release rollback for production incidents. It does not assume a specific cloud provider or CI vendor.

## Rollback principles

- Roll back the application release first when the problem is code-only.
- Do not roll back a database migration blindly.
- Preserve the last known-good artifact and backup until the incident is closed.
- Never delete backups without explicit approval.

## Fast decision tree

1. If the issue is a bad deploy with no schema change, switch back to the previous release and restart workers.
2. If the issue includes a reversible schema change, roll back the release and apply the inverse migration only if it is safe and tested.
3. If the issue includes a destructive or one-way migration, stop and restore from backup into a new target first.
4. If the issue is queue/scheduler related, restart the supervised processes before taking heavier action.

## Production-only commands

Do not run these in the audit workspace unless explicitly approved for the live environment.

```bash
php artisan migrate --force
php artisan queue:restart
php artisan schedule:run
```

## Rollback checklist

- Confirm the incident scope and affected version.
- Capture current logs and `/ready` output.
- Freeze new deploys for the affected service.
- Switch the app to the previous release.
- Restart the queue worker so it loads the restored code.
- Re-run `/ready` and the minimum business smoke checks.
- If the database must be restored, create a fresh target first and verify it before pointing traffic at it.

## Migration caveat

Rollback is not guaranteed when a migration:

- deletes or overwrites production data
- backfills into a schema that cannot be cleanly reversed
- changes constraints in a way that blocks the old release

In those cases, the safe path is: backup, restore into a fresh target, validate, then cut traffic over only after sign-off.
