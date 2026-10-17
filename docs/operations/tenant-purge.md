# Tenant purge

Owner: operations  
Related: [platform-queue.md](./platform-queue.md), [BACKUP_RESTORE.md](./BACKUP_RESTORE.md), [DEPLOYMENT.md](./DEPLOYMENT.md)

A tenant (a top-level company with its subsidiaries) is deleted for good with one artisan command, at the server console. There is no web button, no queue job and no flag that skips a check.

```bash
php artisan inbils:platform:purge-company {code}
```

## When it runs

The command refuses unless every one of these holds:

1. `{code}` is the exact code of a top-level company (case-sensitive; a subsidiary is refused: purge its Head).
2. The company and every subsidiary are suspended (`is_active = false`), and `suspended_at` is at least 30 days ago.
3. A platform admin requested the deletion (`deletion_requested_at` is set).
4. A tenant export finished after the suspension (a `ready` or `expired` export). The archive may be gone by now; the record proves it was made.
5. No user homed in the tenant is also a member of a company outside it.
6. A person is at the terminal: `--no-interaction` is refused, and the company code must be typed again after the plan is shown.

Everything is re-checked inside the transaction, with the company row locked, so a reactivation between the question and the delete wins.

## What it does

1. Prints the company ids, the number of users and media files, and the row count of every table it will empty.
2. Writes `tenant.purge_started` to the platform audit (no actor: the system), with the code, name, company ids and those counts.
3. In one transaction: the platform audit rows of the tenant lose their company link and gain `tenant_code` and `tenant_name`; pivot rows, media rows, notifications, API tokens, sessions, reset tokens and role assignments of its users go; the table list comes from the database schema (every table with a `company_id`, children first; trees and the splitter/port cycle are detached first); then the subsidiaries and the Head company. Any error rolls the whole transaction back and writes `tenant.purge_failed`.
4. After the commit it deletes `companies/{id}` on the private and public disks (and every other disk a media row names) and the export archives of the tenant, then writes `tenant.purge_completed`.

Roles, permissions, settings, regions, other tenants and platform accounts are never touched. The `support` entries of the tenant's audit log are tenant data and go with it; the platform audit stays.

## After a failed or partial run

- **Database refused (exit code 1, "database was left unchanged").** Nothing was deleted. The message names the cause (usually a row of another company that points at one of this tenant's users). Fix the data, run it again.
- **Files left (exit code 1, "could not be deleted").** The database part is done and cannot be undone. The command lists each leftover as `disk:path`; remove them by hand (for the private disk that is under `storage/app/private`, for the public disk `storage/app/public`).
- **There is no undo.** The only way back is the last backup ([BACKUP_RESTORE.md](./BACKUP_RESTORE.md)). Take one, and check its date, before running the command on a tenant that matters.

Run it in a quiet period: it deletes in a single transaction, and a tenant with millions of audit rows holds its locks for as long as that takes. The tenant itself has been suspended for a month and sends no traffic.
