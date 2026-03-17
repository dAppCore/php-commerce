<?php

declare(strict_types=1);

use Core\Mod\Commerce\Controllers\InvoiceController;
use Core\Mod\Commerce\View\Modal\Admin\CouponManager;
use Core\Mod\Commerce\View\Modal\Admin\CreditNoteManager;
use Core\Mod\Commerce\View\Modal\Admin\EntityManager;
use Core\Mod\Commerce\View\Modal\Admin\OrderManager;
use Core\Mod\Commerce\View\Modal\Admin\PermissionMatrixManager;
use Core\Mod\Commerce\View\Modal\Admin\ProductManager;
use Core\Mod\Commerce\View\Modal\Admin\ReferralManager;
use Core\Mod\Commerce\View\Modal\Admin\SubscriptionManager;
use Core\Mod\Commerce\View\Modal\Web\ChangePlan;
use Core\Mod\Commerce\View\Modal\Web\Dashboard;
use Core\Mod\Commerce\View\Modal\Web\Invoices;
use Core\Mod\Commerce\View\Modal\Web\PaymentMethods;
use Core\Mod\Commerce\View\Modal\Web\ReferralDashboard;
use Core\Mod\Commerce\View\Modal\Web\Subscription;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Hub Routes (Billing & Admin)
|--------------------------------------------------------------------------
*/

// Billing (user-facing hub pages)
Route::prefix('hub/billing')->name('hub.billing.')->group(function () {
    Route::get('/', Dashboard::class)->name('index');
    Route::get('/invoices', Invoices::class)->name('invoices');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::get('/invoices/{invoice}/view', [InvoiceController::class, 'view'])->name('invoices.view');
    Route::get('/payment-methods', PaymentMethods::class)->name('payment-methods');
    Route::get('/subscription', Subscription::class)->name('subscription');
    Route::get('/change-plan', ChangePlan::class)->name('change-plan');
    Route::get('/affiliates', ReferralDashboard::class)->name('affiliates');
});

// Commerce management (admin only - Hades tier)
Route::prefix('hub/commerce')->name('hub.commerce.')->group(function () {
    Route::get('/', Core\Mod\Commerce\View\Modal\Admin\Dashboard::class)->name('dashboard');
    Route::get('/orders', OrderManager::class)->name('orders');
    Route::get('/subscriptions', SubscriptionManager::class)->name('subscriptions');
    Route::get('/coupons', CouponManager::class)->name('coupons');
    Route::get('/entities', EntityManager::class)->name('entities');
    Route::get('/permissions', PermissionMatrixManager::class)->name('permissions');
    Route::get('/products', ProductManager::class)->name('products');
    Route::get('/credit-notes', CreditNoteManager::class)->name('credit-notes');
    Route::get('/referrals', ReferralManager::class)->name('referrals');
});
