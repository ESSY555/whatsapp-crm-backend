# FlowCRM backend — PRD implementation status

Maps every section of `PRD.md` to what exists in this repository. All items below are implemented and covered by the automated test suite (`php artisan test`, 108 tests / 782 assertions) unless marked otherwise.

## Architecture & tenancy (PRD §2–7, §33–36, §55–57)

| Requirement | Implementation |
| --- | --- |
| Shared DB / shared schema, `business_id` on every tenant table | All tenant tables carry `business_id` with FK + composite indexes |
| Centralised tenant context | `app/Tenancy/TenantContext.php` (+ `runAs()` for jobs/commands), `TenantManager` facade |
| Tenant resolution from membership, never from request data | `App\Http\Middleware\SetTenantContext` (only an *already authorised* membership can be selected with `X-Business-ID`) |
| Global scope + auto-assignment | `App\Tenancy\Scopes\BusinessScope`, `App\Models\Concerns\BelongsToBusiness` |
| `business_users` membership table with roles | `business_users` (owner / manager), `EnsureBusinessRole` middleware (`business.role:owner,manager`) |
| Versioned REST API, standard envelope, Form Requests, services | `routes/api.php` (`/api/v1`), `ApiResponse` trait, `app/Http/Requests/**`, `app/Services/*` |
| Exception handling | `bootstrap/app.php` renders 401/403/404/422/429/500 in the standard envelope |

## Domain features

| PRD section | Endpoints / code |
| --- | --- |
| §8–10 Businesses, users, settings | `BusinessController` (profile, logo upload, key/value settings), `UserController` (team list, invite, role change, remove) |
| §11–12 Customers & groups | `CustomerController` (+ balances, search on name/business/phone/email, group filter, CSV bulk import with per-row report and template download via `CustomerImportService`), `CustomerGroupController`, `CustomerService` |
| §13 Products | `ProductController` (unique SKU per business, soft deletes) |
| §14–16 Invoices, items, statuses | `InvoiceController`, `InvoiceService` (server-side totals, auto numbering, draft/sent editing only, status derived from payments + due date, cancel instead of delete, `send`, `remind`) |
| §17–18 Payments & debt | `PaymentController`, `PaymentService` (row lock, balance check, unique reference, `Idempotency-Key`, immutable rows + reversal entries) |
| §19 Ageing | `ReportService::ageing()` — current / 1–30 / 31–60 / 61–90 / 90+ from `due_date` + `balance_due` |
| §20 WhatsApp connections | `WhatsAppConnectionController` — token encrypted at rest, hidden from JSON, verify endpoint |
| §21–22 Conversations & messages | `ConversationController`, `MessageController`, `WhatsAppService` (queue-first sending, status ranking, template rendering) |
| §23 Webhooks | `WhatsAppWebhookController` (verify handshake, `X-Hub-Signature-256` validation, queued `ProcessWhatsAppWebhook`, idempotent on WhatsApp message id) |
| §24 Templates | `MessageTemplateController` (`/whatsapp/templates` and `/templates`), `{{placeholder}}` rendering |
| §25 Broadcasts | `BroadcastController`, `BroadcastService`, `SendBroadcastMessages` job (batched, re-queues itself, pause/cancel) |
| §26 Scheduled messages | `ScheduledMessageController`, `ScheduledMessageService`, `SendScheduledMessage` job, `messages:dispatch-due` every minute |
| §27–28 Reminder rules & logs | `ReminderRuleController`, `ReminderService` (rule matching, duplicate prevention via `reminder_logs`), `ProcessPaymentReminders`, `reminders:process` daily 09:00 |
| §29 Dashboard | `GET /api/v1/dashboard` — metrics, 6-month trend, ageing, recent invoices/payments/messages/activity |
| §30 Reports | `GET /api/v1/reports/{sales,customers,debt,messaging}` with date-range filtering |
| §31 Notifications | `NotificationController`, `NotificationService` — payment received, invoice overdue, message failed, broadcast completed, scheduled message sent, connection failure |
| §32 Audit logs | `AuditLogController`, `AuditLogger` — customers, invoices, payments (record/reverse), connections, templates, business, team roles |
| §39 Queues | `SendWhatsAppMessage`, `SendBroadcastMessages`, `SendScheduledMessage`, `ProcessPaymentReminders`, `ProcessWhatsAppWebhook`, `GenerateInvoicePdf` |
| §41 Soft deletes | customers, products, businesses; invoices are cancelled, payments reversed |
| §44–45 Security | Sanctum tokens, bcrypt, role middleware, tenant isolation, Form Request validation, throttles on auth / messaging / broadcasts / webhooks, restricted CORS (`config/cors.php`), encrypted credentials, hidden secrets |
| §46–47 Pagination & search | Every list endpoint paginates (`per_page` ≤ 100) and filters; `GET /api/v1/search` powers the command palette |
| §48–49 Files & PDF | Logos on the `public` disk; `GET /invoices/{id}/pdf` renders a branded PDF (logo, business info, tax number, footer setting) via dompdf |
| §52 Migrations | Reversible migrations in dependency order (verified on MySQL 8 and SQLite) |
| §53 Seed data | `database/seeders/DemoSeeder.php` — `demo@flowcrm.test` / `password` (owner), `manager@flowcrm.test` / `password` |
| §58 API documentation | `docs/openapi.yaml`, served at `GET /api/v1/docs` |

## Subscription billing, quotas & message metering (added 2026-09-16)

v1 has **no payment processor**: a business transfers to the platform's bank account, uploads the receipt, and a super admin approves or declines it. The seam for a processor is `App\Enums\PaymentMethod` (`manual_receipt` only today) + `payment_requests.method`; a processor webhook would create an already-approved `PaymentRequest`.

| Spec item | Implementation |
| --- | --- |
| Plans (Starter ₦15,000 / 522, Business ₦30,000 / 1,343, Professional ₦60,000 / 2,985 Utility msgs per 30-day cycle) | `plans` table (`price`+`currency`, `utility_quota` = included messages, `marketing_quota`, `allows_broadcasts`, `is_trial_default`, `is_active`), `PlanSeeder` (idempotent, updates price/quota, never re-activates a deactivated plan). Managed at `/api/v1/admin/plans`. |
| Marketing excluded from plan quota (spec option a) | Every seeded plan has `marketing_quota = 0`, `allows_broadcasts = false`; broadcasts and marketing-category templates are refused with "not included in your plan — contact support". Per-category allowance later = flip the two columns in the admin UI. |
| Usage by category | `messages.billing_category` (`utility` / `marketing` / `authentication` / `service`) set at send time by `QuotaService::categoryFor()`; `message_usage` counters per business × subscription × cycle × category. Authentication is priced like Utility by Meta and draws from the same included messages; service-window replies are free. |
| Count on **delivery**, not send | `WhatsAppService::applyStatus()` calls `QuotaService::recordDelivered()` on the `delivered`/`read` webhook (idempotent via `messages.charged_at`); failures go to `failed_count` and are never charged. Queued/sent-but-undelivered messages are *reserved* (`inFlightCount`) so a burst cannot overshoot the quota. |
| Quota enforcement | `QuotaService::assertCanSend()` inside `WhatsAppService::sendMessage()` (covers manual, reminders, scheduled, broadcasts); broadcasts also pre-checked in `BroadcastService::create/schedule/start` and paused mid-batch when quota runs out. **Hard-block** is the v1 policy (`config/billing.php` `hard_block`; `false` = allow overage and flag via the `quota_exhausted` notification). |
| Billing cycle | 30 days from approval (`plans.interval_days`); a renewal approved while a period is running is stacked after it. Usage period = the subscription window (monthly anniversaries for longer plans); trial usage is keyed to the trial window. |
| Receipt flow | `POST /billing/payment-requests` (owner only; JPG/PNG/WEBP/PDF ≤ 5 MB on the private disk, `amount_claimed`, reference, note; one pending per business) · `GET /billing` (state, plan, cycle, usage incl. `by_category`, plans, bank accounts) · `GET /billing/payment-requests[/{id}/receipt]`. Admin: `GET /admin/payment-requests` (queue) · `GET …/{id}` (+ `amount_mismatch`, business billing summary, history) · `GET …/{id}/receipt` · `POST …/{id}/approve` · `POST …/{id}/decline` (reason required). Approval creates the `subscriptions` row; decline stores `decline_reason`; both notify the owner in-app + email (`PaymentRequestReviewed`) and write to `audit_logs`. |
| Lifecycle | `subscriptions.status`: `active` → `grace` (`config('billing.grace_days')`, default 5; full access + renewal banner) → `expired` (outbound WhatsApp and broadcasts blocked, CRM data readable) by `subscriptions:expire` (00:10 daily); a stacked renewal expires the old period without grace; 7-day-before warning. Payment progress is exposed separately as `payment_state` (`none` / `pending` / `declined`) so a declined receipt leaves the entitlement untouched. Business-level `state`: `trial` / `active` / `grace` / `expired` / `suspended`. |
| Trial | 14 days from registration (`config('billing.trial_days')`), Starter quotas, broadcasts locked. Admin can extend. |
| Frontend | `/billing` (current plan, usage progress bar + per-category table + days remaining, plan cards, bank details, receipt upload with claimed amount, payment history); global banners (trial ending, under review, declined, grace, expired, quota nearly used); admin `/admin/payments` queue and `/admin/payments/:id` review page (inline receipt, claimed-vs-price warning, approve / decline with reason). |
| Super admin | `users.is_super_admin` (granted via `php artisan admin:grant <email>` or `/admin/users`), `super.admin` middleware, `/admin/login` + `POST /auth/admin/login`, console at `/admin/*` (dashboard, businesses incl. grant plan / extend trial / suspend / impersonate, plans & bank accounts, users, WhatsApp connections, cross-tenant audit log, queue health). |

Tests: `tests/Feature/Api/V1/MessageQuotaTest.php` (delivery-based charging, failed messages not charged, exhaustion hard-block + in-flight reservation, overage mode, marketing isolation, authentication pool, service window, period rollover, active→grace→expired access rules, stacked renewal) and `BillingAndAdminTest.php` (receipt upload/validation, roles, admin approve/decline/renewal stacking, expiry command, admin management, impersonation, admin login).

Not built in this pass (by spec): processor checkout/webhooks, metered overage invoices, changes to WhatsApp onboarding.

## Scheduler

`routes/console.php` registers: `messages:dispatch-due` (every minute), `invoices:mark-overdue` (00:05 daily), `subscriptions:expire` (00:10 daily), `reminders:process` (09:00 daily). Run `php artisan schedule:work` locally or add `* * * * * php artisan schedule:run` in production.

## Tests (PRD §50–51)

- `tests/Feature/Auth/*` — registration, login, logout, password reset, email verification
- `tests/Feature/Tenancy/TenantIsolationTest.php` and cross-tenant assertions inside every feature suite — customers, invoices, payments, messages, conversations, broadcasts, templates, reminders, notifications, audit logs; `business_id` in payloads is ignored
- `tests/Feature/Api/V1/*` — CRM, sales, payments (full, partial, duplicate, reversal, idempotency), WhatsApp (queueing, Cloud API job, failure handling, webhooks, status ordering), automation (broadcast batches, scheduling, reminder selection and duplicate prevention, overdue command), dashboard & reports, notifications, audit logs, team management
- `tests/Feature/DemoSeederTest.php` — the demo workspace is consistent and idempotent
- `tests/Feature/Api/V1/{MessageQuotaTest,BillingAndAdminTest}.php` — billing, quotas, lifecycle and the super-admin console (see section above)

## Not in scope / notes

- WhatsApp media messages (images/documents) are stored as inbound `type` only; outbound is text/template.
- Meta-side template submission/approval is tracked by status on `message_templates`; approval itself happens in Meta Business Manager.
- Redis is the intended production queue; the local `.env` uses the `database` driver so the app runs without Redis.
