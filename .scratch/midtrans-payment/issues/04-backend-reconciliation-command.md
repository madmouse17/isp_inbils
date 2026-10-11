# BACKEND: scheduled reconciliation of pending Midtrans attempts

Status: done
Role: backend
Blocked by: 02
Card: none (new lane; no Hermes card)

## Scope

- `midtrans:reconcile {--company=} {--dry-run}`: for each active company (via `CompanyService::runAs`, like the billing commands), take attempts still `pending` older than a grace period (default 10 min) or past `expires_at`; call `MidtransGateway::transactionStatus`; verify the response signature when present; feed it to `PaymentAttemptService::applyNotification` (same code path as the webhook); mark attempts past expiry with no remote transaction as `expired`.
- Schedule every 15 minutes with `withoutOverlapping()` in `routes/console.php`; no-op when the gateway is not configured.
- Output a summary (checked, settled, expired, exceptions); `--dry-run` writes nothing and prints what would change.
- Gateway/network errors for one attempt must not stop the run or change state; log a redacted warning.

Non-goals: report page, notifications, refund handling.

## Acceptance criteria

- [x] A pending attempt whose remote status is `settlement` (Http::fake) gets exactly one payment, same as the webhook path; running the command twice adds nothing.
- [x] A pending attempt past expiry with remote `expire`/404 becomes `expired`; one still within the grace period is untouched.
- [x] `--dry-run` changes no rows; `--company` limits to one company and never touches another's attempts.
- [x] A failing HTTP call for one attempt does not abort the others and leaves that attempt `pending`.
- [x] With no server key the command exits 0 and does nothing.
- [x] Scheduler registration visible in `php artisan schedule:list` (every 15 minutes, `withoutOverlapping`).
