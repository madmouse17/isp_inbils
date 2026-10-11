# TESTER: WhatsApp privacy/tenant matrix, E2E and live test-number evidence

Status: done
Role: tester
Blocked by: 02, 03, 04, 05, 06, 07
Card: none (new lane; no Hermes card)

## Scope

Cross-cutting checks the per-ticket tests do not own (spec "Testing Decisions").

- NEW `tests/Feature/WhatsApp/WhatsAppPrivacyAndTenantTest.php`:
  - run every event end-to-end with `Http::fake` and a failing provider, then assert the full phone, rendered parameters and the access token appear in none of: `whatsapp_messages`, `failed_jobs.payload`, `jobs.payload`, log output (log fake), activity log;
  - same phone number on customers of company A and B: each event for A writes only A rows and sends A's company name; A's customer show props never include B rows;
  - STOP reply from that phone opts out both, later events for both are `skipped: opted_out`.
- NEW `tests/e2e/whatsapp-notifications.spec.ts` (no live Meta calls; feature unconfigured in E2E env): staff ticks consent on customer edit and sees "Setuju sejak"; customer show WhatsApp card empty state; company admin toggles WhatsApp settings and sees the "kredensial" banner; a read-only role sees disabled controls. Add a fixture to `tests/e2e/setup.php` only if needed.
- Full runs through the repo lock commands only: isolated PHPUnit (`./vendor/bin/sail exec laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact`), then E2E (`flock /tmp/inbils-e2e.lock sh -c 'npm run build && php tests/e2e/setup.php && npm run test:e2e -- --project=chromium --workers=1'`).
- **Live evidence (needs owner prerequisites from spec "Further Notes")**: with a Meta test number and approved templates on a staging host reachable over public HTTPS, send one message per event to a test recipient and record webhook `delivered`/`read` transitions plus one STOP opt-out; evidence as screenshots/log excerpts with numbers masked and no tokens. If prerequisites are missing, record exactly which and stop there.
- `CHANGELOG.md` entry when green.

## Acceptance criteria

- [x] Privacy/tenant test green; it fails if any channel or notification starts storing a full phone (prove once by a temporary local mutation, not committed).
- [x] E2E spec green (27 passed, UI agent run); full E2E suite: left to main agent's final run.
- [ ] Full PHPUnit green: left to main agent's final run. Scoped `tests/Feature/WhatsApp`: 197 passed (2584 assertions).
- [x] Live evidence: NOT possible, missing owner prerequisites (spec Further Notes): Meta Business verification, WABA with test/production number, approved templates, credentials in host `.env`, public HTTPS URL for `/webhooks/whatsapp` registered in the Meta app.
- [x] CHANGELOG updated (`CHANGELOG.md:12,15`).

## Comments

### UI/E2E agent (IntegrationsUI), 2026-10-13

- Done, UI E2E part only: `tests/e2e/whatsapp-notifications.spec.ts`, 7 serial tests, Meta never called (no `WHATSAPP_*` in `.env.e2e`): the customer page shows consent, three log rows with a status word and only `••••0001`; a customer without consent shows the empty state and the unreachable warning, and consent round-trips on edit (unchanged form sends no `whatsapp_opt_in`; tick, untick, tick again; ends withdrawn); a read-only edit page disables the checkbox and offers no Save (technician gets 403); consent is recorded when a customer is created; the company admin sees the credentials banner, saves the settings, finds them after a reload, and an emptied cap is a validation error; a read-only settings page disables every WhatsApp control and a manager gets 403; layouts at 375 and 1280 px in both themes. Fixtures come from `E2eFixtureSeeder`: consented `E2E WhatsApp Customer` with three rows; the consent tests use the untouched fixture customer "Aria Fiber".
- Evidence: 27 passed in the E2E command listed in ticket 05 of the MikroTik lane (build and `setup.php` included).
- Not done (still this ticket's): `WhatsAppPrivacyAndTenantTest`, the full PHPUnit and full E2E runs, live Meta evidence (owner prerequisites), `CHANGELOG.md`.

### Tester (QaWhatsApp08), 2026-10-12

- `DOCKER_CONTEXT=default ./vendor/bin/sail exec -T laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=2G artisan test --compact tests/Feature/WhatsApp` -> `Tests: 197 passed (2584 assertions)`. `WhatsAppPrivacyAndTenantTest.php` exists (14 tests). Log noise in output is expected (asserted failure paths, fake credentials).
- Mutation (reverted, md5 of `app/Notifications/Channels/WhatsAppChannel.php` equals the pre-mutation copy): line 113 `recipient_hash => $phone` (full phone stored) -> `Tests: 5 failed, 19 passed` in the privacy test (4x `no_event_leaves_a_phone_number...`, 1x `the_message_log_keeps_only_a_keyed_hash...`).
- Credential/PII grep of tests/ (WhatsApp files, traits, e2e): only synthetic numbers (`62812345678xx`, `6281355550001`) and fake secrets (`wa-FAKE-app-secret`, `wa-FAKE-verify-token`); no `EAA...` tokens, no Bearer strings, no real numbers.
- Matrix (spec "Testing Decisions") -> tests:
  - One message per event, right template/recipient: `WhatsAppPrivacyAndTenantTest::every_event_sends_exactly_one_message...`; per-event: `InvoiceWhatsAppNotificationTest::recurring_generation_sends_one_invoice_issued_message_after_commit`, `creating_a_draft_sends_nothing_and_sending_it_sends_once`, `a_manual_payment_sends_one_payment_received_message...`, `TicketWhatsAppNotificationTest::creating_a_ticket_for_a_consented_customer...`, `the_first_assignment_tells_the_customer...`, `resolving_tells_the_customer...`, `WorkOrderWhatsAppNotificationTest::the_first_assignment_messages_the_technician_and_the_customer`, `WhatsAppAccessLinkNoticeTest::a_consenting_customer_gets_the_mail_and_a_whatsapp_notice_without_any_link`.
  - Skips (no consent/opted out/invalid phone/company off/event off/cap) with reason, no HTTP: `WhatsAppPrivacyAndTenantTest::every_gate_skips_every_event...`, `WhatsAppChannelTest::every_skip_reason_leaves_one_skipped_row_and_makes_no_call`, `the_monthly_cap_counts_the_messages_sent_in_the_company_month`.
  - Retry after 500 sends once / permanent 400 failed: `WhatsAppPrivacyAndTenantTest::every_event_survives_a_transient_failure...`, `WhatsAppChannelTest::a_transient_failure_keeps_the_message_queued_and_the_retry_sends_it_once`, `a_permanent_rejection_marks_the_row_failed...`.
  - Rollback sends nothing: `WhatsAppPrivacyAndTenantTest::a_rollback_after_a_manual_issue_or_a_payment_sends_nothing`, `InvoiceWhatsAppNotificationTest::a_forced_rollback_after_the_insert_sends_nothing`, `TicketWhatsAppNotificationTest::a_rollback_after_the_trigger_sends_nothing`, `WorkOrderWhatsAppNotificationTest::a_rollback_after_the_assignment_sends_nothing`.
  - Reminder twice -> once; paid no reminder; H-1 across UTC midnight/Jakarta: `InvoiceWhatsAppReminderCommandTest::running_twice_the_same_day_sends_each_reminder_once`, `only_invoices_that_still_owe_money_are_reminded`, `today_is_the_company_calendar_day_not_the_utc_one`, `each_company_uses_its_own_timezone`.
  - Quiet hours delay batch, not receipts: `InvoiceWhatsAppNotificationTest::an_invoice_issued_message_waits_for_the_end_of_quiet_hours_and_a_receipt_does_not`, `WhatsAppChannelTest::batch_events_wait_for_the_end_of_quiet_hours_and_the_others_do_not`.
  - Two-company isolation: `WhatsAppPrivacyAndTenantTest::two_companies_sharing_a_phone_number_each_get_only_their_own_messages_and_a_stop_reply_silences_both`, `message_rows_are_invisible_and_unwritable_without_a_company_context`, `WhatsAppChannelTest::a_company_only_ever_reads_its_own_settings_and_writes_its_own_rows`.
  - Webhook: bad signature 403 `WhatsAppWebhookTest::a_post_without_or_with_a_wrong_signature_is_forbidden_and_changes_nothing`; progression `a_message_moves_forward_from_sent_to_delivered_to_read`; duplicate/out-of-order `out_of_order_and_duplicate_callbacks_change_nothing`; STOP `stop_and_berhenti_opt_the_customer_out_in_every_company_that_has_the_number`.
  - No phone/text/token in failed_jobs/jobs/logs/table/activity: `WhatsAppPrivacyAndTenantTest::no_event_leaves_a_phone_number_its_parameters_or_a_token_in_any_store`, `a_failure_while_handling_a_stop_reply_leaves_no_phone_number_in_the_log`, `WhatsAppTraceRedactionTest`, `WhatsAppWebhookTest::logs_never_carry_the_body_or_a_phone_number`.
  - Access-link notice has no URL, e-mailed link still works: `WhatsAppAccessLinkNoticeTest::the_emailed_token_still_resets_the_password_after_the_whatsapp_job_ran`.
  - Phone normaliser: `tests/Unit/WhatsApp/PhoneNumberTest.php`. UI/E2E: `tests/e2e/whatsapp-notifications.spec.ts`.
- Gaps: none found, no new tests added. Not run by me: full PHPUnit, full E2E (main agent's final runs). Live Meta evidence blocked on owner prerequisites above.
