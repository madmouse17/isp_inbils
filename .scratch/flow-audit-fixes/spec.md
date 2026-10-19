# Flow-audit fixes: the owner's answers K1-K37 turned into work, in the report's three waves

Status: in-progress
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `flow-audit-fixes`
Source: owner 2026-10-19, after the K1-K37 round of `docs/plans/FLOW_AUDIT_2026-10-17.md` §4: "saya ikut saran rekomendasi anda saja". Every recommendation in §4 is accepted; where §4 left a choice, the orchestrator's recommendation below is the decision. Order and grouping: §5 of the report (wave 1 blockers and data integrity, wave 2 high-benefit small UX, wave 3 the rest, with the "same root" groups done once).

## Owner decisions (recommended options, accepted 2026-10-19)

- K1: invoice line amounts and tax are rounded to whole rupiah when the invoice is built, half up (nearest); the manual payment "Jumlah" is prefilled with the open balance (F5-U01); web, apps and PDF show the same figure.
- K2: settled by `tenant-integrations` (per-company Midtrans key and webhook). A signed notification for an unknown `order_id` answers 200 and logs `midtrans.foreign_order` (order id only), so a merchant shared with another project stops retry storms. Splitting the shared sandbox merchant FHTS is a Midtrans account task for the owner.
- K3: Snap always sends `credit_card.secure = true` (payload test). Chargebacks are the ISP's as the merchant of record (its own key since `tenant-integrations`); documented, no code.
- K4: cancelling the pending Midtrans transaction on a cash payment and alerting billing staff shipped in `integrity-hardening`. Refund: manual through the Midtrans dashboard; finance marks the `exception` attempt "Dikembalikan" with a reference and note (audited); no automatic refund API.
- K5: keep rule Q16-b; the customer is told which invoices still hold the reactivation ("Lunasi juga INV-…") with "Bayar semua" and a notification.
- K6: settled by `integrity-hardening` + mobile `15b1aef`; the remaining F4-D07 (stock error reason on SPK approval) is wave 1.
- K7: installation materials are included in the installation fee by default (no material lines on the invoice). Company setting "Tagihkan bahan terpakai" (off by default) bills them as itemised lines under "Bahan terpakai".
- K8: SLA breach and near-breach go to the assignee and managers by push and bell, never by mail to the customer; the `response` SLA is counted too; new tickets and customer replies notify dispatch and managers (F6-D05).
- K9: first response = first public reply by staff; "Mulai" counts when no reply came first; the source is shown.
- K10: check-in farther than 500 m (company setting, default 500) asks for a reason and is flagged; the web shows the distance in km; a photo stays optional.
- K11: per SLA tier option "Kalender 24 jam" (default, today's behaviour) or "Jam layanan perusahaan"; with service hours the customer sees "Dijawab sebelum 08.30 besok".
- K12: default `customer_hint` text per seeded ticket category (written in this lane), with a preview on the web category page.
- K13: a Ditutup ticket refuses replies ("Tiket ditutup. Masih bermasalah? Buat tiket baru"); a customer reply on a Diselesaikan ticket reopens it and notifies the assignee and dispatch; staff reopen a Ditutup ticket with a reason (`ticket.reopen`).
- K14: the customer rates a resolved ticket 1-5 stars with a comment in the app (`customer_rating`); staff use "Evaluasi teknisi" from a Ditutup ticket (F6-U15).
- K15: MOVED to lane `.scratch/tenant-roles` (owner 2026-10-19: CS is part of NOC, and roles must be per company because each ISP names and shapes them differently). That lane also takes the "Peran dan menu" group (F3-D03, F3-U04, F3-U06, F4-D01). This lane never seeds roles or checks role names; a new permission it needs is added to the permission catalogue only, and listed for the tenant-roles templates.
- K16: a visit schedule field on customer registration and in the Assign dialog; the raw 422 page becomes an inline message; "Tugaskan sekarang" option.
- K17: company switch "Kirim tagihan pemasangan otomatis" (asked once at tenant setup, F1-U05); while it is off, a dashboard card "Tagihan draf menunggu dikirim" and a bell to `billing.send` holders.
- K18: the PPPoE network account (username from the customer code, random secret) is created automatically, idempotently, when the installation SPK is first assigned, because the technician needs it on site before review; approval creates it only if it is still missing. Only the currently assigned technician can reveal the secret, only while the SPK is assigned or in progress, through an audited, rate-limited "Tampilkan" action; never in push payloads, list responses, logs or the offline cache; a reassignment ends the previous technician's access. This slice gets a `security-reviewer` pass.
- K19: "Buka kunci masuk" clears the password lock (email+IP) and the 2FA counter, and is shown for every user.
- K20: one Indonesian sentence per support action (`user.*`, `tenant.*`) in the tenant audit, searchable.
- K21: tenant export takes files from the tenant's `media` rows (`mediaOwnedBy`), writes `media.csv` and user roles, never scans folders; dev and test storage roots are separated (F0-D03).
- K22: platform mail carries the Inbils brand, customer mail the ISP name and logo; `.env` examples get real defaults; `/ready` reports `degraded` in production when `MAIL_FROM_ADDRESS` ends in `example.com`.
- K23: tenant creation offers "Katalog awal" (default, editable, no fake-serial assets) or "Kosong"; seeded assets use the Penomoran prefix.
- K24: first admin login prompts to turn on 2FA (skippable for 7 days); company policy "Wajib 2FA untuk admin".
- K25: two isolir texts (tagihan / dibatasi ISP), call and WhatsApp buttons, app links, no web-login link. "Bayar tanpa login" is parked (`docs/PARKING_LOT.md`).
- K26: a Dokumen tab on the customer detail (upload, view, delete, expiry reminder); the menu is renamed "Jenis Dokumen" first.
- K27: the reactivate dialog says "Tagihan berikutnya akan menjadi …"; periods spent suspended are not billed afterwards.
- K28: technicians get bell + push for inventory-request events; approvers and warehouse get bell and dashboard; mail only as an opt-in daily digest.
- K29: document codes unified through `number_sequences` (prefix-year-sequence); page titles on one line.
- K30: "Transfer" modal on the Stok page (existing transfer endpoint).
- K31: settled by `log-audit` (PDF cell cap, 92-day window).
- K32: PDF report template with a header (company, period, filters, printed at), id-ID number format, subset fonts.
- K33: Tunggakan gets CSV/PDF export.
- K34: "Minta anak perusahaan" button that opens a request to the platform operator.
- K35: a returned SPK shows "Menunggu penugasan ulang dari kantor" with a call button in the technician app.
- K36: done 2026-10-19 (`git rm` of the four root files).
- K37: the H-3 test method (due date typed in the manual invoice form) is accepted; only the wall-clock trigger stays untested.

## Non-goals

"Bayar tanpa login" on the isolir page (parked), automatic Midtrans refunds, a Midtrans merchant split (owner's account task), SNMP/RouterOS live link state.

## Tickets

01-design-w1 (architect) · 02-design-w2 (architect) · wave 1 slices · wave 2 slices · 03-design-w3 · wave 3 slices · QA + security review per wave.
