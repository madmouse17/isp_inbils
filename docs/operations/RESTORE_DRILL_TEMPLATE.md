# Restore drill template

Status: provisional until approved by the owner.

Use a throwaway database and an empty throwaway directory. Do not reuse production names.

## Preconditions

- Backup archives already exist in the off-host destination.
- Credentials come from deployment secret material only.
- The target database name is distinct from the source database name.
- The target media directory exists and is empty, or does not exist yet.

## Example flow

```bash
python scripts/operations/restore.py \
  --db-archive /offhost/backups/inbils.daily.2026-08-02T010000Z.sql.gz.enc \
  --media-archive /offhost/backups/inbils.daily.2026-08-02T010000Z.media.tar.gz.enc \
  --checksum-file /offhost/backups/inbils.daily.2026-08-02T010000Z.sha256 \
  --target-db-name restore_inbils_20260802 \
  --target-media-dir /tmp/inbils-restore-drill \
  --db-host "$BACKUP_DB_HOST" \
  --db-port "$BACKUP_DB_PORT" \
  --db-user "$BACKUP_DB_USER" \
  --db-password-file "$BACKUP_DB_PASSWORD_FILE" \
  --encryption-key-file "$BACKUP_ENCRYPTION_KEY_FILE" \
  --cleanup-after-verify \
  --proof-file /offhost/backups/inbils.daily.2026-08-02T010000Z.restore-proof.json
```

## Acceptance checks

- Restore succeeds into the isolated target.
- Checksums validate before restore.
- Temporary restore artifacts are removed.
- The proof file records the cleanup result.
- The target directory is empty again after cleanup.

## Fail closed cases

The restore must stop if any of these are true:

- the target directory is not empty
- the target database equals the source database
- the checksum file is missing or does not match
- the destination archive paths already exist and would be overwritten
- the backup source looks like a protected system database

## Notes

- Keep the drill output separate from any live application storage.
- If the owner later approves the cadence, update the schedule and retention policy in this document and the operational runbook together.
