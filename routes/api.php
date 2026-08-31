<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerGroupController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\DebtReportController;
use App\Http\Controllers\Api\V1\WhatsAppConnectionController;
use App\Http\Controllers\Api\V1\WhatsAppWebhookController;
use App\Http\Middleware\SetTenantContext;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
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

    Route::middleware(['auth:sanctum', SetTenantContext::class])->group(function () {
        Route::apiResource('whatsapp/connections', WhatsAppConnectionController::class)->only(['index', 'store', 'destroy']);
        Route::get('business', [BusinessController::class, 'show']);
        Route::put('business', [BusinessController::class, 'update'])->middleware('business.role:owner,manager');
        Route::get('business/settings', [BusinessController::class, 'settings']);
        Route::put('business/settings', [BusinessController::class, 'updateSettings'])->middleware('business.role:owner,manager');
        Route::apiResource('customers', CustomerController::class);
        Route::apiResource('customer-groups', CustomerGroupController::class)
            ->parameters(['customer-groups' => 'customerGroup']);
        Route::apiResource('products', ProductController::class);
        Route::apiResource('invoices', InvoiceController::class);
        Route::apiResource('payments', PaymentController::class)->only(['index', 'store', 'show']);
        Route::get('reports/debt', [DebtReportController::class, 'index']);
    });
    Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
    Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive']);
});
