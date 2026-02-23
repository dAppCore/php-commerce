<?php

declare(strict_types=1);

namespace Core\Mod\Commerce;

use Core\Events\AdminPanelBooting;
use Core\Events\ApiRoutesRegistering;
use Core\Events\ConsoleBooting;
use Core\Events\WebRoutesRegistering;
use Core\Mod\Commerce\Listeners\ProvisionSocialHostSubscription;
use Core\Mod\Commerce\Listeners\RewardAgentReferralOnSubscription;
use Core\Mod\Commerce\Services\PaymentGateway\BTCPayGateway;
use Core\Mod\Commerce\Services\PaymentGateway\PaymentGatewayContract;
use Core\Mod\Commerce\Services\PaymentGateway\StripeGateway;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Commerce Module Boot
 *
 * Orders, subscriptions, and billing engine.
 *
 * Service layer: Service\Commerce\Boot
 */
class Boot extends ServiceProvider
{
    protected string $moduleName = 'commerce';

    /**
     * Events this module listens to for lazy loading.
     *
     * @var array<class-string, string>
     */
    public static array $listens = [
        AdminPanelBooting::class => 'onAdminPanel',
        ApiRoutesRegistering::class => 'onApiRoutes',
        WebRoutesRegistering::class => 'onWebRoutes',
        ConsoleBooting::class => 'onConsole',
    ];

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Migrations');

        // Laravel event listeners (not lifecycle events)
        Event::subscribe(ProvisionSocialHostSubscription::class);
        Event::listen(\Core\Mod\Commerce\Events\SubscriptionCreated::class, RewardAgentReferralOnSubscription::class);
        Event::listen(\Core\Mod\Commerce\Events\SubscriptionRenewed::class, Listeners\ResetUsageOnRenewal::class);
        Event::listen(\Core\Mod\Commerce\Events\OrderPaid::class, Listeners\CreateReferralCommission::class);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/config.php',
            $this->moduleName
        );

        // Core Services
        $this->app->singleton(\Core\Mod\Commerce\Services\CommerceService::class);
        $this->app->singleton(\Core\Mod\Commerce\Services\SubscriptionService::class);
        $this->app->singleton(\Core\Mod\Commerce\Services\InvoiceService::class);
        $this->app->singleton(\Core\Mod\Commerce\Services\PermissionMatrixService::class);
        $this->app->singleton(\Core\Mod\Commerce\Services\CouponService::class);
        $this->app->singleton(\Core\Mod\Commerce\Services\TaxService::class);
        $this->app->singleton(\Core\Mod\Commerce\Services\CurrencyService::class);
        $this->app->singleton(\Core\Mod\Commerce\Services\ContentOverrideService::class);
        $this->app->singleton(\Core\Mod\Commerce\Services\DunningService::class);
        $this->app->singleton(\Core\Mod\Commerce\Services\SkuParserService::class);
        $this->app->singleton(\Core\Mod\Commerce\Services\SkuBuilderService::class);
        $this->app->singleton(\Core\Mod\Commerce\Services\CreditNoteService::class);
        $this->app->singleton(\Core\Mod\Commerce\Services\PaymentMethodService::class);
        $this->app->singleton(\Core\Mod\Commerce\Services\UsageBillingService::class);
        $this->app->singleton(\Core\Mod\Commerce\Services\ReferralService::class);
        $this->app->singleton(\Core\Mod\Commerce\Services\FraudService::class);
        $this->app->singleton(\Core\Mod\Commerce\Services\CheckoutRateLimiter::class);
        $this->app->singleton(\Core\Mod\Commerce\Services\WebhookRateLimiter::class);

        // Payment Gateways
        $this->app->singleton('commerce.gateway.btcpay', function ($app) {
            return new BTCPayGateway;
        });

        $this->app->singleton('commerce.gateway.stripe', function ($app) {
            return new StripeGateway;
        });

        $this->app->bind(PaymentGatewayContract::class, function ($app) {
            $defaultGateway = config('commerce.gateways.btcpay.enabled')
                ? 'btcpay'
                : 'stripe';

            return $app->make("commerce.gateway.{$defaultGateway}");
        });
    }

    // -------------------------------------------------------------------------
    // Event-driven handlers
    // -------------------------------------------------------------------------

    public function onAdminPanel(AdminPanelBooting $event): void
    {
        $event->views($this->moduleName, __DIR__.'/View/Blade');

        if (file_exists(__DIR__.'/Routes/admin.php')) {
            $event->routes(fn () => require __DIR__.'/Routes/admin.php');
        }

        // Admin Livewire components
        $event->livewire('commerce.admin.subscription-manager', View\Modal\Admin\SubscriptionManager::class);
        $event->livewire('commerce.admin.order-manager', View\Modal\Admin\OrderManager::class);
        $event->livewire('commerce.admin.coupon-manager', View\Modal\Admin\CouponManager::class);
        $event->livewire('commerce.admin.dashboard', View\Modal\Admin\Dashboard::class);
        $event->livewire('commerce.admin.entity-manager', View\Modal\Admin\EntityManager::class);
        $event->livewire('commerce.admin.permission-matrix-manager', View\Modal\Admin\PermissionMatrixManager::class);
        $event->livewire('commerce.admin.product-manager', View\Modal\Admin\ProductManager::class);
        $event->livewire('commerce.admin.credit-note-manager', View\Modal\Admin\CreditNoteManager::class);
        $event->livewire('commerce.admin.referral-manager', View\Modal\Admin\ReferralManager::class);
    }

    public function onApiRoutes(ApiRoutesRegistering $event): void
    {
        if (file_exists(__DIR__.'/Routes/api.php')) {
            $event->routes(fn () => Route::middleware('api')->group(__DIR__.'/Routes/api.php'));
        }
    }

    public function onWebRoutes(WebRoutesRegistering $event): void
    {
        if (file_exists(__DIR__.'/Routes/web.php')) {
            $event->routes(fn () => Route::middleware(['web', 'auth'])->group(__DIR__.'/Routes/web.php'));
        }

        // Note: Checkout routes are provided by each frontage (lt.hn, Hub, etc.)
        // Commerce module provides the backend services only

        // Web/User facing Livewire components (for Hub integration)
        $event->livewire('commerce.web.subscription', View\Modal\Web\Subscription::class);
        $event->livewire('commerce.web.invoices', View\Modal\Web\Invoices::class);
        $event->livewire('commerce.web.dashboard', View\Modal\Web\Dashboard::class);
        $event->livewire('commerce.web.payment-methods', View\Modal\Web\PaymentMethods::class);
        $event->livewire('commerce.web.change-plan', View\Modal\Web\ChangePlan::class);
        $event->livewire('commerce.web.checkout-page', View\Modal\Web\CheckoutPage::class);
        $event->livewire('commerce.web.checkout-success', View\Modal\Web\CheckoutSuccess::class);
        $event->livewire('commerce.web.checkout-cancel', View\Modal\Web\CheckoutCancel::class);
        $event->livewire('commerce.web.currency-selector', View\Modal\Web\CurrencySelector::class);
        $event->livewire('commerce.web.usage-dashboard', View\Modal\Web\UsageDashboard::class);
        $event->livewire('commerce.web.referral-dashboard', View\Modal\Web\ReferralDashboard::class);
    }

    public function onConsole(ConsoleBooting $event): void
    {
        $event->command(Console\ProcessDunning::class);
        $event->command(Console\SendRenewalReminders::class);
        $event->command(Console\PlantSubscriberTrees::class);
        $event->command(Console\CleanupExpiredOrders::class);
        $event->command(Console\RefreshExchangeRates::class);
        $event->command(Console\SyncUsageToStripe::class);
        $event->command(Console\MatureReferralCommissions::class);
    }
}
