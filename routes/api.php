<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BroadcastController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerGroupController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\MessageTemplateController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReminderRuleController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ScheduledMessageController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WhatsAppConnectionController;
use App\Http\Controllers\Api\V1\WhatsAppWebhookController;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // ---------------------------------------------------------------- Auth
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,1');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('user', [AuthController::class, 'user']);
            Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
                ->middleware(['signed', 'throttle:6,1'])
                ->name('verification.verify');
            Route::post('email/verification-notification', [AuthController::class, 'resendVerification'])
                ->middleware('throttle:6,1');
        });
    });

    // ------------------------------------------------------ Tenant-scoped API
    Route::middleware(['auth:sanctum', SetTenantContext::class])->group(function () {
        // Business & team
        Route::get('business', [BusinessController::class, 'show']);
        Route::put('business', [BusinessController::class, 'update'])->middleware('business.role:owner,manager');
        Route::post('business/logo', [BusinessController::class, 'uploadLogo'])->middleware('business.role:owner,manager');
        Route::get('business/settings', [BusinessController::class, 'settings']);
        Route::put('business/settings', [BusinessController::class, 'updateSettings'])->middleware('business.role:owner,manager');

        Route::get('users', [UserController::class, 'index']);
        Route::middleware('business.role:owner')->group(function () {
            Route::post('users/invite', [UserController::class, 'invite'])->middleware('throttle:20,1');
            Route::put('users/{user}/role', [UserController::class, 'updateRole']);
            Route::delete('users/{user}', [UserController::class, 'destroy']);
        });

        // CRM
        Route::get('customers/import/template', [CustomerController::class, 'importTemplate']);
        Route::post('customers/import', [CustomerController::class, 'import'])->middleware('throttle:10,1');
        Route::apiResource('customers', CustomerController::class);
        Route::apiResource('customer-groups', CustomerGroupController::class)
            ->parameters(['customer-groups' => 'customerGroup']);

        // Sales
        Route::apiResource('products', ProductController::class);
        Route::apiResource('invoices', InvoiceController::class);
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf']);
        Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])->middleware('throttle:60,1');
        Route::post('invoices/{invoice}/remind', [InvoiceController::class, 'remind'])->middleware('throttle:60,1');

        // Payments
        Route::apiResource('payments', PaymentController::class)->only(['index', 'store', 'show']);
        Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse'])->middleware('business.role:owner');

        // WhatsApp
        Route::apiResource('whatsapp/connections', WhatsAppConnectionController::class)
            ->only(['index', 'store', 'destroy'])
            ->parameters(['connections' => 'connection'])
            ->middleware('business.role:owner,manager');
        Route::post('whatsapp/connections/{connection}/verify', [WhatsAppConnectionController::class, 'verify'])
            ->middleware(['business.role:owner,manager', 'throttle:10,1']);

        Route::apiResource('whatsapp/templates', MessageTemplateController::class)->parameters(['templates' => 'template']);
        Route::apiResource('templates', MessageTemplateController::class)->parameters(['templates' => 'template'])->names('templates.alias');

        Route::apiResource('conversations', ConversationController::class)->only(['index', 'store', 'show', 'update']);
        Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages']);

        Route::apiResource('messages', MessageController::class)->only(['index', 'show']);
        Route::post('messages', [MessageController::class, 'store'])->middleware('throttle:60,1');
        Route::post('messages/{message}/retry', [MessageController::class, 'retry'])->middleware('throttle:60,1');

        // Broadcasts & scheduling
        Route::apiResource('broadcasts', BroadcastController::class)->only(['index', 'show', 'destroy']);
        Route::post('broadcasts', [BroadcastController::class, 'store'])->middleware('throttle:10,1');
        Route::post('broadcasts/{broadcast}/recipients', [BroadcastController::class, 'addRecipients']);
        Route::post('broadcasts/{broadcast}/schedule', [BroadcastController::class, 'schedule']);
        Route::post('broadcasts/{broadcast}/start', [BroadcastController::class, 'start'])->middleware('throttle:10,1');
        Route::post('broadcasts/{broadcast}/pause', [BroadcastController::class, 'pause']);
        Route::post('broadcasts/{broadcast}/cancel', [BroadcastController::class, 'cancel']);

        Route::get('scheduled-messages/stats', [ScheduledMessageController::class, 'stats']);
        Route::apiResource('scheduled-messages', ScheduledMessageController::class)
            ->parameters(['scheduled-messages' => 'scheduledMessage']);
        Route::post('scheduled-messages/{scheduledMessage}/cancel', [ScheduledMessageController::class, 'cancel']);

        // Automated reminders
        Route::get('reminders/trigger-types', [ReminderRuleController::class, 'triggerTypes']);
        Route::get('reminders/preview', [ReminderRuleController::class, 'preview']);
        Route::get('reminders/logs', [ReminderRuleController::class, 'logs']);
        Route::post('reminders/run', [ReminderRuleController::class, 'run'])->middleware('throttle:5,1');
        Route::apiResource('reminders', ReminderRuleController::class)->parameters(['reminders' => 'reminder']);

        // Dashboard & reports
        Route::get('dashboard', [ReportController::class, 'dashboard']);
        Route::get('reports/sales', [ReportController::class, 'sales']);
        Route::get('reports/customers', [ReportController::class, 'customers']);
        Route::get('reports/debt', [ReportController::class, 'debt']);
        Route::get('reports/messaging', [ReportController::class, 'messaging']);

        // Notifications & audit
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::delete('notifications/{notification}', [NotificationController::class, 'destroy']);

        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('business.role:owner,manager');
        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->middleware('business.role:owner,manager');

        Route::get('search', SearchController::class);
    });

    // ---------------------------------------------------------- Webhooks
    Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->middleware('throttle:60,1');
    Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])->middleware('throttle:600,1');

    // API documentation (OpenAPI)
    Route::get('docs', fn () => response()->file(base_path('docs/openapi.yaml'), ['Content-Type' => 'application/yaml']));
});
