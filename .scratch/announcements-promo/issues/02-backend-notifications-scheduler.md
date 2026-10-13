# BACKEND: outage/maintenance notifications, scheduled start, image cleanup

Status: ready-for-agent
Role: backend
Blocked by: 01
Spec: `../spec.md`

## Scope (owned files)

`AnnouncementPublishedNotification` (database channel, Indonesian copy, deep link payload `{type:'announcement', id}`), queued fan-out job (chunked, once per user, idempotent), scheduler entries: publish-at-start for scheduled outage/maintenance announcements (every minute or five), banner image cleanup (> 180 days after end). Tests per spec.

## Acceptance criteria

- [ ] Focused PHPUnit green; Pint; `routes/console.php` schedule listed in `docs/operations`.

## Comments
