<?php

declare(strict_types=1);

use Core\Mod\Commerce\Controllers\Api\CommerceController;
use Core\Mod\Commerce\Controllers\Webhooks\BTCPayWebhookController;
use Core\Mod\Commerce\Controllers\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Commerce API Routes
|--------------------------------------------------------------------------
|
| API routes for the Commerce module including payment webhooks,
| billing management, and provisioning endpoints.
|
*/

// ─────────────────────────────────────────────────────────────────────────────
// Payment Webhooks (no auth - uses signature verification + IP-based rate limiting)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('webhooks')->group(function () {
    // Rate limiting is handled per-IP in the controllers via WebhookRateLimiter
    // This provides better protection than global throttle middleware:
    // - Per-IP limits (60/min default, 300/min for trusted gateway IPs)
    // - Different limits per gateway
    // - Proper 429 responses with Retry-After headers
    Route::post('/btcpay', [BTCPayWebhookController::class, 'handle'])
        ->name('api.webhook.btcpay');
    Route::post('/stripe', [StripeWebhookController::class, 'handle'])
        ->name('api.webhook.stripe');
});

// ─────────────────────────────────────────────────────────────────────────────
// Commerce Provisioning API (Bearer token auth)
// TODO: Create ProductApiController and EntitlementApiController in
//       Mod\Commerce\Controllers\Api\ for provisioning endpoints
// ─────────────────────────────────────────────────────────────────────────────

// Route::middleware('commerce.api')->prefix('provisioning')->group(function () {
//     Route::get('/ping', [ProductApiController::class, 'ping'])->name('api.commerce.ping');
//     Route::get('/products', [ProductApiController::class, 'index'])->name('api.commerce.products');
//     Route::get('/products/{code}', [ProductApiController::class, 'show'])->name('api.commerce.products.show');
//     Route::post('/entitlements', [EntitlementApiController::class, 'store'])->name('api.commerce.entitlements.store');
//     Route::get('/entitlements/{id}', [EntitlementApiController::class, 'show'])->name('api.commerce.entitlements.show');
//     Route::post('/entitlements/{id}/suspend', [EntitlementApiController::class, 'suspend'])->name('api.commerce.entitlements.suspend');
//     Route::post('/entitlements/{id}/unsuspend', [EntitlementApiController::class, 'unsuspend'])->name('api.commerce.entitlements.unsuspend');
//     Route::post('/entitlements/{id}/cancel', [EntitlementApiController::class, 'cancel'])->name('api.commerce.entitlements.cancel');
//     Route::post('/entitlements/{id}/renew', [EntitlementApiController::class, 'renew'])->name('api.commerce.entitlements.renew');
// });

// ─────────────────────────────────────────────────────────────────────────────
// Commerce Billing API (authenticated)
// ─────────────────────────────────────────────────────────────────────────────

Route::middleware('auth')->prefix('commerce')->group(function () {
    // Billing overview
    Route::get('/billing', [CommerceController::class, 'billing'])
        ->name('api.commerce.billing');

    // Orders
    Route::get('/orders', [CommerceController::class, 'orders'])
        ->name('api.commerce.orders.index');
    Route::get('/orders/{order}', [CommerceController::class, 'showOrder'])
        ->name('api.commerce.orders.show');

    // Invoices
    Route::get('/invoices', [CommerceController::class, 'invoices'])
        ->name('api.commerce.invoices.index');
    Route::get('/invoices/{invoice}', [CommerceController::class, 'showInvoice'])
        ->name('api.commerce.invoices.show');
    Route::get('/invoices/{invoice}/download', [CommerceController::class, 'downloadInvoice'])
        ->name('api.commerce.invoices.download');

    // Subscription
    Route::get('/subscription', [CommerceController::class, 'subscription'])
        ->name('api.commerce.subscription');
    Route::post('/cancel', [CommerceController::class, 'cancelSubscription'])
        ->name('api.commerce.cancel');
    Route::post('/resume', [CommerceController::class, 'resumeSubscription'])
        ->name('api.commerce.resume');

    // Usage
    Route::get('/usage', [CommerceController::class, 'usage'])
        ->name('api.commerce.usage');

    // Plan changes
    Route::post('/upgrade/preview', [CommerceController::class, 'previewUpgrade'])
        ->name('api.commerce.upgrade.preview');
    Route::post('/upgrade', [CommerceController::class, 'executeUpgrade'])
        ->name('api.commerce.upgrade');
});
