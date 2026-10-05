# Crediping API

Multi-tenant Laravel 12 backend for Crediping — customers, invoices, payments, debt collection and WhatsApp messaging for SMEs. The product requirements live in [PRD.md](PRD.md); [IMPLEMENTATION_STATUS.md](IMPLEMENTATION_STATUS.md) maps every PRD section to the code that implements it.

## Requirements

- PHP 8.2+, Composer
- MySQL 8 (tests run on in-memory SQLite)
- Redis for the production queue (the `database` queue driver works locally)

## Setup

```bash
composer install
cp .env.example .env          # then set DB_*, FRONTEND_URL, WHATSAPP_* values
php artisan key:generate
php artisan migrate --seed    # seeds the demo workspace
php artisan storage:link      # serves uploaded business logos
```

Demo accounts created by the seeder:

| Role | Email | Password |
| --- | --- | --- |
| Owner | `demo@flowcrm.test` | `password` |
| Manager | `manager@flowcrm.test` | `password` |

## Running

```bash
php artisan serve                 # API on http://127.0.0.1:8000
php artisan queue:work            # delivers WhatsApp messages, broadcasts, reminders
php artisan schedule:work         # scheduled messages, overdue detection, daily reminders
```

The frontend (`../whatsapp-crm-frontend`) expects `VITE_API_URL=http://127.0.0.1:8000/api/v1`. Allowed browser origins are configured with `CORS_ALLOWED_ORIGINS` (defaults to `FRONTEND_URL`).

## WhatsApp Cloud API

1. Set `WHATSAPP_VERIFY_TOKEN` and `WHATSAPP_APP_SECRET` in `.env`.
2. Point the Meta webhook to `https://<your-host>/api/v1/webhooks/whatsapp` (subscribe to `messages`).
3. In the app, open **Settings → WhatsApp connection** and save the phone number id, WABA id and a permanent access token. Tokens are encrypted at rest and never returned by the API.

Inbound messages, delivery receipts and failures arrive through the webhook and are processed on the queue.

## API

- Base URL: `/api/v1`, bearer-token authentication (Laravel Sanctum).
- Every response uses `{ "success": bool, "message": string, "data": ... }`; validation errors add `errors`.
- The tenant is resolved from the authenticated user's business membership — `business_id` in requests is ignored.
- Full OpenAPI description: [docs/openapi.yaml](docs/openapi.yaml) (also served at `GET /api/v1/docs`).

## Tests

```bash
php artisan test
```

Covers authentication, tenant isolation for every resource, CRM, invoices/PDFs, payments (partial, duplicate, reversal, idempotency), WhatsApp sending/webhooks, broadcasts, scheduling, automated reminders, dashboard/reports, notifications, audit logs and team management.
