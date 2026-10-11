# MOBILE: notification inbox in `inbils_core` (shared) and in the customer app

Status: ready-for-agent
Role: mobile
Blocked by: 05
Cross-lane blocked by: mobile-api 02 (inbox endpoints); customer events need `.scratch/whatsapp-notifications` done (API Q18)
Card: none (new lane; no Hermes card)
Skills: antislop-ui, antislop-human, antislop-layoutmobile, tdd, ponytail, caveman

## Scope

Implements the core inbox (API Q18a) and wires it into the customer app. The technician app reuses the core screen in its own ticket 08.

- Core:
  - Notification model (`id`, `type`, `title`, `message`, `subject {type, id}`, `read_at`, `created_at`) and a repository for `GET notifications`, `POST notifications/{id}/read` and `POST notifications/read-all`.
  - Inbox screen: unread first-class styling (bold plus a dot plus the semantics label "belum dibaca"), infinite scroll, pull to refresh, "Tandai semua dibaca", and an empty state "Belum ada notifikasi".
  - Opening an item marks it read, then calls a route callback supplied by the host app with `subject`.
  - Unread-count provider for badges.
- Customer:
  - Route `/notifications`; the Home bell (prepared in 05) appears and opens it.
  - `subject` routing: `invoice` → `/invoices/:id`, `ticket` → `/tickets/:id`, anything else → no navigation.
  - Refresh the badge on app resume.

Non-goals: push, local notifications, notification preferences.

## Owned files

- NEW `../inbils-mobile/packages/inbils_core/lib/src/notifications/` (and its tests and ARB strings)
- NEW `../inbils-mobile/customer/lib/features/notifications/` (subject routing only), `../inbils-mobile/customer/lib/features/home/` (bell only), `../inbils-mobile/customer/lib/app/router.dart`, `../inbils-mobile/customer/test/`

## Acceptance criteria

- [ ] Core widget tests:
  - unread items are distinguishable without colour (semantics label checked);
  - tapping an item sends `POST …/read` with an `Idempotency-Key` and then invokes the callback with `subject`;
  - "Tandai semua dibaca" sends one request and clears all unread markers;
  - empty, error and offline states render.
- [ ] Customer tests: an invoice subject opens the invoice detail, a ticket subject opens the ticket, and an unknown subject stays in the list. The badge count follows `meta.total` and updates on resume.
- [ ] Live on the AVD against the compose stack, with the WhatsApp lane done and an invoice issued for the seeded customer: the notification appears, opens the invoice and becomes read; the web shows nothing for this customer that the app lacks. If the WhatsApp lane is not done yet, record that only the empty state could be verified, and keep this criterion open.
- [ ] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments
