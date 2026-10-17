# BACKEND: outage/maintenance notifications, scheduled start, image cleanup

Status: done
Role: backend
Blocked by: 01
Spec: `../spec.md`

## Scope (owned files)

`AnnouncementPublishedNotification` (database channel, Indonesian copy, deep link payload `{type:'announcement', id}`), queued fan-out job (chunked, once per user, idempotent), scheduler entries: publish-at-start for scheduled outage/maintenance announcements (every minute or five), banner image cleanup (> 180 days after end). Tests per spec.

## Acceptance criteria

- [x] Focused PHPUnit green; Pint; `routes/console.php` schedule listed in `docs/operations`.

## Comments

- `announcements:publish-scheduled` runs every minute with overlap protection. `promo-banners:cleanup-images` runs daily and keeps the banner row.
- Fan-out uses `SendAnnouncementNotifications` (`ShouldQueue`, `ShouldBeUnique`, 3 tries, 30/120/300 second backoff, 120 second timeout), chunks customers by 500, skips inactive/unlinked users, and checks stored database notifications before each send.
- Database payload includes `type=announcement_published`, `announcement_id`, company/kind/title snapshots, and deep-link subject `{type:announcement,id}`. Promos and `info` announcements never dispatch.
