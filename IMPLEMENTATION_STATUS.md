# FlowCRM backend — PRD implementation status

Maps every section of `PRD.md` to what exists in this repository. All items below are implemented and covered by the automated test suite (`php artisan test`, 81 tests / 513 assertions) unless marked otherwise.

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

## Scheduler

`routes/console.php` registers: `messages:dispatch-due` (every minute), `invoices:mark-overdue` (00:05 daily), `reminders:process` (09:00 daily). Run `php artisan schedule:work` locally or add `* * * * * php artisan schedule:run` in production.

## Tests (PRD §50–51)

- `tests/Feature/Auth/*` — registration, login, logout, password reset, email verification
- `tests/Feature/Tenancy/TenantIsolationTest.php` and cross-tenant assertions inside every feature suite — customers, invoices, payments, messages, conversations, broadcasts, templates, reminders, notifications, audit logs; `business_id` in payloads is ignored
- `tests/Feature/Api/V1/*` — CRM, sales, payments (full, partial, duplicate, reversal, idempotency), WhatsApp (queueing, Cloud API job, failure handling, webhooks, status ordering), automation (broadcast batches, scheduling, reminder selection and duplicate prevention, overdue command), dashboard & reports, notifications, audit logs, team management
- `tests/Feature/DemoSeederTest.php` — the demo workspace is consistent and idempotent

## Not in scope / notes

- WhatsApp media messages (images/documents) are stored as inbound `type` only; outbound is text/template.
- Meta-side template submission/approval is tracked by status on `message_templates`; approval itself happens in Meta Business Manager.
- Redis is the intended production queue; the local `.env` uses the `database` driver so the app runs without Redis.
