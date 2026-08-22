# FlowCRM

## Multi-Tenant Backend Product Requirements Document

**Version:** 1.0
**Product:** FlowCRM
**Backend:** PHP 8.2+ / Laravel 12
**Database:** MySQL 8+
**Architecture:** Multi-Tenant SaaS
**Tenancy Strategy:** Shared Database / Shared Schema
**API:** REST API
**Authentication:** Laravel Sanctum
**Queue:** Laravel Queue + Redis
**Scheduler:** Laravel Scheduler

---

# 1. Backend Objective

Build a production-ready, multi-tenant Laravel backend for FlowCRM.

FlowCRM allows manufacturers, distributors, wholesalers, and SMEs to manage:

* Businesses
* Users
* Customers
* Customer groups
* WhatsApp conversations
* WhatsApp messages
* Message templates
* Broadcasts
* Scheduled messages
* Products
* Invoices
* Payments
* Outstanding debts
* Automated reminders
* Reports
* Notifications
* Business settings
* WhatsApp Business integrations

The backend must provide a secure REST API consumed by the FlowCRM frontend.

---

# 2. Multi-Tenancy Architecture

## 2.1 Tenancy Model

FlowCRM will use:

> **Shared database + shared schema + business-level tenant isolation**

Example:

```text
                    FlowCRM
                       │
              ┌────────┴────────┐
              │                 │
         Business A        Business B
              │                 │
        ┌─────┴─────┐     ┌─────┴─────┐
        │           │     │           │
     Users      Customers Users     Customers
     Invoices    Payments Invoices   Payments
     Messages    Reports  Messages   Reports
```

All businesses use the same database.

Every tenant-owned record must contain:

```text
business_id
```

Example:

```text
customers
--------------------------------
id
business_id
name
phone
email
...
```

Business A must never be able to access Business B's customer.

---

# 3. Tenant Isolation — Critical Requirement

Tenant isolation must be enforced **server-side**.

Do not rely on:

```text
WHERE business_id = request.business_id
```

being manually added to every controller.

That approach is error-prone.

Instead, implement a centralized tenancy architecture.

Recommended structure:

```text
app/
├── Tenancy/
│   ├── TenantContext.php
│   ├── TenantManager.php
│   └── Middleware/
│       └── ResolveTenant.php
│
├── Models/
│   ├── Concerns/
│   │   └── BelongsToBusiness.php
│   │
│   └── Scopes/
│       └── BusinessScope.php
```

---

# 4. Tenant Context

Create a central `TenantContext` service responsible for identifying the authenticated user's current business.

Example conceptual flow:

```text
Request
   ↓
Authentication
   ↓
Resolve User
   ↓
Resolve Business
   ↓
TenantContext
   ↓
Business Scope
   ↓
Controller
```

Controllers should not need to manually determine the tenant.

---

# 5. Tenant Global Scope

Create a reusable Laravel global scope.

Example conceptual behavior:

```php
BusinessScope
```

It automatically applies:

```sql
WHERE business_id = CURRENT_BUSINESS_ID
```

to tenant-owned models.

Models should use a reusable trait:

```php
BelongsToBusiness
```

Example:

```php
class Customer extends Model
{
    use BelongsToBusiness;
}
```

When creating records, the backend should automatically assign:

```php
business_id
```

from the authenticated tenant context.

Never accept `business_id` directly from normal frontend request payloads.

---

# 6. Tenant-Owned vs Global Data

Not every table needs `business_id`.

## Global/platform tables

Examples:

```text
countries
currencies
system_settings
```

These are platform-wide.

## Tenant-owned tables

Examples:

```text
users
customers
customer_groups
products
invoices
invoice_items
payments
conversations
messages
broadcasts
message_templates
scheduled_messages
notifications
```

These must belong to a business.

---

# 7. Core Database Architecture

The database should be organized around the following domains:

```text
Business
│
├── Users
├── Customer Management
│   ├── Customers
│   └── Customer Groups
│
├── Sales
│   ├── Products
│   ├── Invoices
│   └── Invoice Items
│
├── Payments
│   └── Payments
│
├── WhatsApp
│   ├── Connections
│   ├── Conversations
│   ├── Messages
│   ├── Templates
│   ├── Broadcasts
│   └── Scheduled Messages
│
├── Automation
│   └── Reminder Rules
│
├── Notifications
│
└── Audit Logs
```

---

# 8. Businesses Table

Table:

```text
businesses
```

Fields:

```text
id
name
slug
logo
email
phone
address
city
state
country
currency
timezone
tax_number
status
created_at
updated_at
```

Status:

```text
active
suspended
trial
cancelled
```

`slug` should be unique.

---

# 9. Users Table

Users belong to a business.

```text
users
```

Fields:

```text
id
business_id
name
email
phone
password
role
email_verified_at
status
remember_token
created_at
updated_at
```

Roles:

```text
owner
manager
```

A user must not be able to switch to another business by manipulating a request parameter.

---

# 10. Business Membership Architecture

For better long-term scalability, consider separating authentication from business membership.

Recommended structure:

```text
users
    id
    name
    email
    password

businesses
    id
    name

business_users
    id
    business_id
    user_id
    role
    status
```

This architecture is preferable if a user may eventually belong to multiple businesses.

For MVP, if each user can belong to exactly one business, `business_id` on `users` is acceptable.

However, **I recommend `business_users` now** if FlowCRM is intended to become a serious SaaS product.

It prevents an expensive migration later when users need access to multiple businesses.

---

# 11. Customer Database

```text
customers

id
business_id
name
business_name
phone
email
address
category
notes
status
created_at
updated_at
deleted_at
```

Indexes:

```text
business_id
business_id + phone
business_id + email
business_id + name
```

The combination of tenant ID + frequently searched fields should be indexed.

---

# 12. Customer Groups

```text
customer_groups

id
business_id
name
description
created_at
updated_at
```

Relationship:

```text
customer_group_members

id
business_id
customer_id
customer_group_id
created_at
```

This allows customers to belong to multiple groups.

---

# 13. Products

```text
products

id
business_id
name
sku
description
unit_price
tax_rate
status
created_at
updated_at
deleted_at
```

SKU should be unique within a business:

```text
UNIQUE(business_id, sku)
```

---

# 14. Invoices

```text
invoices

id
business_id
customer_id
invoice_number
issue_date
due_date
subtotal
discount
tax
total
amount_paid
balance_due
status
notes
created_by
created_at
updated_at
```

Invoice number should be unique per business:

```text
UNIQUE(business_id, invoice_number)
```

---

# 15. Invoice Items

```text
invoice_items

id
business_id
invoice_id
product_id
description
quantity
unit_price
tax_rate
tax_amount
subtotal
total
created_at
updated_at
```

Even child records should contain `business_id` where appropriate.

This provides an additional layer of tenant protection.

---

# 16. Invoice Status

Supported statuses:

```text
draft
sent
paid
partially_paid
overdue
cancelled
```

The backend should determine invoice status based on payment and due-date information.

Do not allow the frontend to arbitrarily set:

```text
paid
```

without a corresponding payment transaction.

---

# 17. Payments

Create a dedicated payments table.

```text
payments

id
business_id
customer_id
invoice_id
amount
payment_method
reference
payment_date
notes
recorded_by
created_at
updated_at
```

Payment methods:

```text
cash
bank_transfer
card
mobile_money
other
```

Payments must be immutable from an accounting perspective.

If a payment needs correction, preferably create a reversal/adjustment rather than silently modifying historical financial data.

---

# 18. Debt Management

Outstanding debt should be calculated from financial records.

Conceptually:

```text
Invoice Total
      -
Payments
      =
Outstanding Balance
```

Do not maintain multiple independent sources of truth.

The backend should calculate:

```text
total_invoiced
total_paid
total_outstanding
```

at the business and customer levels.

---

# 19. Debt Ageing

Support:

```text
Current
1–30 Days
31–60 Days
61–90 Days
90+ Days
```

Ageing should be calculated from:

```text
due_date
balance_due
```

rather than manually entered by users.

---

# 20. WhatsApp Connections

```text
whatsapp_connections

id
business_id
phone_number_id
business_account_id
access_token_encrypted
status
connected_at
last_verified_at
created_at
updated_at
```

Never return access tokens through API responses.

Credentials must be encrypted at rest.

---

# 21. WhatsApp Conversations

```text
conversations

id
business_id
customer_id
whatsapp_connection_id
status
last_message_at
created_at
updated_at
```

Statuses:

```text
open
closed
archived
```

---

# 22. Messages

```text
messages

id
business_id
conversation_id
customer_id
direction
type
body
whatsapp_message_id
status
error_message
sent_at
delivered_at
read_at
created_at
updated_at
```

Direction:

```text
inbound
outbound
```

Status:

```text
queued
sending
sent
delivered
read
failed
```

---

# 23. WhatsApp Webhooks

Implement:

```http
GET  /api/webhooks/whatsapp
POST /api/webhooks/whatsapp
```

Handle:

* Incoming messages
* Sent messages
* Delivered messages
* Read messages
* Failed messages

Webhook processing must be:

* Validated
* Authenticated where required
* Idempotent
* Logged
* Queue-friendly

Do not perform expensive processing directly inside the webhook request.

---

# 24. Message Templates

```text
message_templates

id
business_id
name
category
language
content
whatsapp_template_id
status
variables
created_at
updated_at
```

Statuses:

```text
draft
pending
approved
rejected
disabled
```

---

# 25. Broadcasts

```text
broadcasts

id
business_id
name
template_id
status
total_recipients
sent_count
delivered_count
failed_count
scheduled_at
started_at
completed_at
created_by
created_at
updated_at
```

Recipients:

```text
broadcast_recipients

id
business_id
broadcast_id
customer_id
status
message_id
sent_at
created_at
updated_at
```

Broadcasts must use queues.

Never loop through thousands of recipients inside a normal HTTP request.

---

# 26. Scheduled Messages

```text
scheduled_messages

id
business_id
customer_id
template_id
content
scheduled_at
status
sent_at
failed_at
created_at
updated_at
```

Statuses:

```text
scheduled
processing
sent
failed
cancelled
```

Laravel Scheduler should dispatch due jobs.

---

# 27. Automated Payment Reminders

Create configurable reminder rules.

```text
reminder_rules

id
business_id
name
trigger_type
days_offset
template_id
enabled
created_at
updated_at
```

Examples:

```text
3 days before due date
Due today
3 days overdue
7 days overdue
14 days overdue
30 days overdue
```

The automation engine should:

1. Find qualifying invoices.
2. Determine whether a reminder has already been sent.
3. Select the appropriate template.
4. Queue the WhatsApp message.
5. Record the reminder.
6. Prevent duplicate reminders.

---

# 28. Reminder History

Create:

```text
reminder_logs

id
business_id
invoice_id
customer_id
reminder_rule_id
message_id
sent_at
status
created_at
```

This prevents duplicate automation.

---

# 29. Dashboard API

Provide aggregated endpoints for:

```http
GET /api/dashboard
```

Metrics:

```text
total_customers
total_invoices
total_sales
total_collections
total_outstanding
overdue_amount
```

Also provide:

* Revenue trends
* Collection trends
* Outstanding debt
* Recent activity
* Recent payments
* Recent messages

Dashboard queries must be tenant-scoped.

---

# 30. Reports

Reports should support:

### Customer Report

* Total customers
* New customers
* Customer groups
* Top customers

### Sales Report

* Total sales
* Invoice count
* Paid invoices
* Outstanding invoices
* Date-range filtering

### Debt Report

* Total outstanding
* Overdue amount
* Customer balances
* Ageing buckets

### Messaging Report

* Messages sent
* Delivered
* Read
* Failed
* Broadcast performance

All reports must be scoped to the authenticated business.

---

# 31. Notifications

Create:

```text
notifications

id
business_id
user_id
type
title
message
data
read_at
created_at
updated_at
```

Notification events include:

* Payment received
* Invoice overdue
* Message failed
* Broadcast completed
* Scheduled message sent
* WhatsApp connection failure

---

# 32. Audit Logs

For a CRM dealing with financial records, audit logging should be part of MVP.

Create:

```text
audit_logs

id
business_id
user_id
action
entity_type
entity_id
old_values
new_values
ip_address
user_agent
created_at
```

Log important actions such as:

* Customer creation
* Customer deletion
* Invoice creation
* Invoice modification
* Payment recording
* Payment reversal
* WhatsApp connection changes
* Template changes
* User role changes

---

# 33. API Structure

Use versioned APIs:

```text
/api/v1
```

Example:

```text
/api/v1/auth
/api/v1/business
/api/v1/users
/api/v1/customers
/api/v1/customer-groups
/api/v1/products
/api/v1/invoices
/api/v1/payments
/api/v1/conversations
/api/v1/messages
/api/v1/broadcasts
/api/v1/templates
/api/v1/scheduled-messages
/api/v1/reminders
/api/v1/reports
/api/v1/dashboard
/api/v1/notifications
```

External webhooks:

```text
/api/v1/webhooks/whatsapp
```

---

# 34. API Response Standard

Use a consistent response structure.

Success:

```json
{
    "success": true,
    "message": "Customer created successfully.",
    "data": {}
}
```

Validation error:

```json
{
    "success": false,
    "message": "Validation failed.",
    "errors": {}
}
```

Unauthorized:

```json
{
    "success": false,
    "message": "Unauthenticated."
}
```

Forbidden:

```json
{
    "success": false,
    "message": "You do not have permission to perform this action."
}
```

---

# 35. Validation

All API input must be validated through Laravel Form Requests.

Do not place large validation blocks directly inside controllers.

Example:

```text
StoreCustomerRequest
UpdateCustomerRequest
StoreInvoiceRequest
RecordPaymentRequest
SendMessageRequest
CreateBroadcastRequest
```

---

# 36. Business Logic Architecture

Avoid putting business logic directly into controllers.

Recommended architecture:

```text
Controller
    ↓
Form Request
    ↓
Service
    ↓
Model / Repository
    ↓
Database
```

Examples:

```text
CustomerService
InvoiceService
PaymentService
WhatsAppService
BroadcastService
ReminderService
ReportService
```

---

# 37. Financial Integrity

Financial calculations must be performed server-side.

Never trust frontend values for:

```text
subtotal
tax
discount
total
amount_paid
balance_due
```

Use database transactions for operations involving multiple financial records.

Example:

```text
Record Payment
      ↓
BEGIN TRANSACTION
      ↓
Create Payment
      ↓
Recalculate Invoice
      ↓
Update Invoice Status
      ↓
Create Activity
      ↓
COMMIT
```

If any operation fails, the transaction should roll back.

---

# 38. Concurrency Protection

The backend must protect against duplicate payments and race conditions.

Use:

* Database transactions
* Row locking where appropriate
* Unique transaction references
* Idempotency keys where appropriate

A customer must never have the same payment accidentally recorded twice because of a repeated request.

---

# 39. Queue Architecture

Use Laravel queues for:

```text
SendWhatsAppMessage
SendBroadcastMessage
SendScheduledMessage
SendPaymentReminder
ProcessWhatsAppWebhook
GenerateInvoicePdf
SendNotification
```

Recommended flow:

```text
HTTP Request
     ↓
Database
     ↓
Queue Job
     ↓
External API
     ↓
Result
     ↓
Database
```

---

# 40. Database Transactions

Use transactions for operations such as:

### Invoice creation

```text
Invoice
+
Invoice Items
```

### Payment recording

```text
Payment
+
Invoice balance
+
Invoice status
+
Activity
```

### Business registration

```text
Business
+
Owner
+
Default settings
```

---

# 41. Soft Deletes

Use soft deletes where historical data should be preserved.

Recommended:

```text
customers
products
```

Be careful with deleting:

```text
invoices
payments
messages
```

Financial and communication records should generally not be physically deleted.

Use cancellation/reversal/status changes instead.

---

# 42. Database Indexing

All tenant-owned tables should be carefully indexed.

At minimum:

```text
business_id
```

Frequently queried combinations should use composite indexes.

Examples:

```text
business_id + customer_id
business_id + status
business_id + created_at
business_id + due_date
business_id + phone
business_id + invoice_number
```

Do not blindly add indexes to every column.

Indexes should correspond to actual query patterns.

---

# 43. Foreign Key Constraints

Use proper foreign keys.

Example:

```text
customers.business_id
        ↓
businesses.id
```

```text
invoices.customer_id
        ↓
customers.id
```

```text
invoice_items.invoice_id
        ↓
invoices.id
```

Use appropriate cascading/restrict behavior.

Do not casually use `cascadeOnDelete()` on financial records.

---

# 44. Security Requirements

The backend must implement:

* Password hashing
* Authentication
* Authorization
* Tenant isolation
* Request validation
* Rate limiting
* CSRF protection where applicable
* Secure CORS configuration
* Encrypted WhatsApp credentials
* Secure webhook validation
* SQL injection protection through Eloquent/query builder
* Mass assignment protection
* Sensitive data filtering

Never expose:

```text
password
access_token
app_secret
webhook_secret
```

through API responses.

---

# 45. Rate Limiting

Apply rate limits to sensitive endpoints:

```text
login
registration
password reset
WhatsApp message sending
broadcast creation
webhooks
```

Broadcast/message endpoints should have additional protection against accidental abuse.

---

# 46. API Pagination

Large datasets must be paginated.

Especially:

```text
customers
invoices
payments
messages
conversations
broadcasts
audit logs
notifications
```

Do not return thousands of records in one API response.

---

# 47. Search

Implement server-side search.

Customer search should support:

```text
name
business_name
phone
email
```

Invoice search:

```text
invoice_number
customer
status
date
```

Messages:

```text
customer
conversation
date
status
```

---

# 48. File Storage

Support secure storage for:

* Business logos
* Invoice PDFs
* Customer documents where required
* WhatsApp media where necessary

Use Laravel Filesystem.

Do not store large files directly inside database tables.

---

# 49. Invoice PDF Generation

The backend should generate professional invoice PDFs.

The PDF must use the business information:

```text
Business name
Logo
Address
Phone
Email
Tax information
```

Invoice:

```text
Customer
Invoice number
Issue date
Due date
Line items
Subtotal
Tax
Discount
Total
Amount paid
Balance
```

Provide an endpoint such as:

```http
GET /api/v1/invoices/{id}/pdf
```

---

# 50. Testing Requirements

The backend must have automated tests for critical functionality.

Minimum tests:

### Authentication

* Registration
* Login
* Logout
* Password reset
* Email verification

### Tenancy

* Business A cannot access Business B customers
* Business A cannot access Business B invoices
* Business A cannot access Business B payments
* Business A cannot access Business B messages
* Business A cannot manipulate `business_id`

### Customers

* CRUD
* Search
* Groups

### Invoices

* Creation
* Calculation
* Status changes
* PDF generation

### Payments

* Full payment
* Partial payment
* Duplicate prevention
* Invoice balance calculation

### WhatsApp

* Message creation
* Webhook processing
* Delivery status updates
* Failed messages

### Automation

* Reminder selection
* Duplicate reminder prevention
* Scheduled messages

---

# 51. Critical Multi-Tenancy Security Tests

These tests are mandatory.

Example:

```text
Business A
Customer A

Business B
Customer B
```

Authenticated as Business A:

```http
GET /api/v1/customers/{Customer B ID}
```

Must return:

```text
404
```

or an appropriate tenant-safe response.

It must never return Customer B's data.

Repeat this test for:

```text
Customers
Invoices
Payments
Messages
Conversations
Broadcasts
Templates
Products
Notifications
Audit Logs
```

Also test malicious requests such as:

```json
{
    "business_id": 2
}
```

The backend must ignore/reject tenant IDs supplied by users where they conflict with the authenticated tenant.

---

# 52. Database Migration Strategy

Build the database through Laravel migrations.

Do not manually create production tables.

Recommended migration order:

```text
1. businesses
2. users / business_users
3. business_settings
4. customer_groups
5. customers
6. products
7. invoices
8. invoice_items
9. payments
10. whatsapp_connections
11. conversations
12. messages
13. message_templates
14. broadcasts
15. broadcast_recipients
16. scheduled_messages
17. reminder_rules
18. reminder_logs
19. notifications
20. audit_logs
```

Each migration must be reversible where practical.

---

# 53. Seed Data

Create development seeders for:

* Demo business
* Demo owner
* Demo manager
* Demo customers
* Demo customer groups
* Demo products
* Demo invoices
* Demo payments
* Demo message templates

Do not use production credentials in seeders.

---

# 54. Environment Configuration

Sensitive configuration must use `.env`.

Examples:

```env
APP_NAME=FlowCRM
APP_ENV=local
APP_KEY=

DB_CONNECTION=mysql
DB_HOST=
DB_PORT=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

QUEUE_CONNECTION=redis

WHATSAPP_APP_ID=
WHATSAPP_APP_SECRET=
WHATSAPP_ACCESS_TOKEN=
WHATSAPP_VERIFY_TOKEN=
WHATSAPP_PHONE_NUMBER_ID=
```

Never commit real credentials.

---

# 55. Laravel Project Structure

Recommended structure:

```text
app/
├── Actions/
├── Console/
├── Events/
├── Exceptions/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       └── V1/
│   ├── Middleware/
│   └── Requests/
│
├── Jobs/
├── Listeners/
├── Models/
│
├── Notifications/
├── Policies/
├── Services/
│   ├── Customer/
│   ├── Invoice/
│   ├── Payment/
│   ├── WhatsApp/
│   ├── Broadcast/
│   └── Reporting/
│
├── Tenancy/
│   ├── TenantContext.php
│   ├── TenantManager.php
│   ├── Middleware/
│   └── Scopes/
│
└── Support/
```

---

# 56. API Authentication Flow

Recommended:

```text
Register
   ↓
Business Created
   ↓
Owner Created
   ↓
Email Verification
   ↓
Login
   ↓
Sanctum Token
   ↓
Authenticated API Requests
   ↓
Resolve Business
   ↓
Tenant Context
   ↓
Tenant-Scoped Data
```

---

# 57. Tenant Resolution

For MVP, the tenant should be resolved from the authenticated user's business membership.

Do not trust:

```text
X-Business-ID
business_id query parameter
business_id request body
```

as the primary source of tenant identity.

The authenticated user's membership determines the tenant.

If multi-business users are introduced later, tenant switching must be explicitly authorized.

---

# 58. API Documentation

Document the API using OpenAPI/Swagger or an equivalent system.

Every endpoint should document:

* Method
* URL
* Authentication requirement
* Required permissions
* Request body
* Validation rules
* Response
* Error responses
* Pagination
* Filters

---

# 59. MVP Development Phases

Do not implement everything simultaneously.

## Phase 1 — Foundation

Implement:

```text
Laravel setup
Database
Business
Authentication
Users
Roles
Tenant Context
Tenant Scope
API structure
Exception handling
```

**Do not proceed until tenant isolation is tested.**

---

## Phase 2 — CRM

Implement:

```text
Customers
Customer Groups
Customer Search
Customer Activity
```

---

## Phase 3 — Sales

Implement:

```text
Products
Invoices
Invoice Items
Invoice PDFs
Invoice statuses
```

---

## Phase 4 — Payments & Debt

Implement:

```text
Payments
Partial payments
Outstanding balances
Debt ageing
Payment history
```

---

## Phase 5 — WhatsApp

Implement:

```text
WhatsApp connection
Webhooks
Conversations
Messages
Templates
Message status
```

---

## Phase 6 — Broadcast & Automation

Implement:

```text
Broadcasts
Scheduling
Reminder Rules
Automated Payment Reminders
Queue workers
```

---

## Phase 7 — Dashboard & Reports

Implement:

```text
Dashboard
Sales reports
Customer reports
Debt reports
Messaging reports
```

---

## Phase 8 — Notifications & Audit

Implement:

```text
Notifications
Audit Logs
Activity feeds
```

---

# 60. Definition of Done

The backend is considered MVP-ready only when:

### Architecture

* Laravel backend is properly structured.
* API is versioned.
* Database is normalized.
* Foreign keys are implemented.
* Appropriate indexes exist.

### Multi-Tenancy

* Every tenant-owned record is isolated.
* Tenant context is centralized.
* Global tenant scopes are implemented.
* Users cannot manipulate `business_id`.
* Cross-tenant access tests pass.

### Authentication

* Registration works.
* Login works.
* Logout works.
* Password reset works.
* Email verification works.
* Roles and permissions work.

### CRM

* Customer CRUD works.
* Groups work.
* Search/filter works.

### Sales

* Invoices work.
* Invoice items work.
* Calculations are server-side.
* PDFs work.

### Payments

* Full payments work.
* Partial payments work.
* Outstanding balances are accurate.
* Duplicate payments are prevented.

### WhatsApp

* Connection works.
* Messages work.
* Webhooks work.
* Message statuses work.
* Templates work.

### Automation

* Scheduled messages work.
* Payment reminders work.
* Duplicate reminders are prevented.
* Queues process jobs correctly.

### Reporting

* Dashboard metrics are accurate.
* Reports are tenant-scoped.
* Date filtering works.

### Security

* Authorization is enforced.
* Tenant isolation is tested.
* Sensitive credentials are protected.
* Rate limiting is implemented.
* Validation is enforced.

---

# 61. Non-Negotiable Engineering Rules

1. **Never compromise tenant isolation for convenience.**

2. **Never trust `business_id` supplied by the frontend.**

3. **Never put critical financial calculations only in React/frontend code.**

4. **Never process large WhatsApp broadcasts synchronously.**

5. **Never expose WhatsApp access tokens through API responses.**

6. **Never silently modify historical payment records.**

7. **Never duplicate business logic across controllers.**

8. **Never create a second implementation of an existing service without checking the existing architecture first.**

9. **Do not modify unrelated frontend functionality while implementing the backend.**

10. **Every new tenant-owned model must explicitly define how tenant isolation is enforced.**

11. **Every API endpoint returning business data must be tenant-scoped.**

12. **Every financial operation must use appropriate database transactions.**

13. **Every external WhatsApp operation must be designed for failure, retry, and idempotency.**

14. **Do not build future features into MVP just because they might eventually be useful.**

15. **Database architecture must be completed and reviewed before implementing the majority of business logic.**

---

# 62. Recommended Initial Database Relationship

The core relationship should ultimately look approximately like this:

```text
                         BUSINESS
                            │
             ┌──────────────┼──────────────┐
             │              │              │
           USERS        CUSTOMERS       PRODUCTS
             │              │
             │              │
             │          ┌───┴────┐
             │          │        │
             │       INVOICES   GROUPS
             │          │
             │     INVOICE ITEMS
             │          │
             │       PAYMENTS
             │
             │
             └──────── WHATSAPP ─────────┐
                                         │
                                  CONNECTIONS
                                         │
                                  CONVERSATIONS
                                         │
                                     MESSAGES
                                         │
                         ┌───────────────┴──────────────┐
                         │                              │
                    BROADCASTS                     TEMPLATES
                         │
                  RECIPIENTS
                         │
                   SCHEDULED
                         │
                  AUTOMATIONS
```

The critical architectural rule is:

```text
BUSINESS
   ↓
TENANT CONTEXT
   ↓
ALL BUSINESS DATA
```

No request should be able to escape that boundary.
