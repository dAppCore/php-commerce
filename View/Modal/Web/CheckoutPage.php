<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\View\Modal\Web;

use Core\Mod\Commerce\Models\Coupon;
use Core\Mod\Commerce\Models\ExchangeRate;
use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Services\CheckoutRateLimiter;
use Core\Mod\Commerce\Services\CommerceService;
use Core\Mod\Commerce\Services\CouponService;
use Core\Mod\Commerce\Services\CurrencyService;
use Core\Mod\Commerce\Services\TaxService;
use Core\Tenant\Models\Package;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('shared::layouts.checkout')]
class CheckoutPage extends Component
{
    // URL parameters
    #[Url]
    public string $plan = '';

    #[Url]
    public string $cycle = 'monthly';

    // Form state
    public int $step = 1;

    public ?int $selectedPackageId = null;

    public string $billingCycle = 'monthly';

    // Billing details
    public string $billingName = '';

    public string $billingEmail = '';

    public string $billingAddressLine1 = '';

    public string $billingAddressLine2 = '';

    public string $billingCity = '';

    public string $billingState = '';

    public string $billingPostalCode = '';

    public string $billingCountry = 'GB';

    public string $taxId = '';

    // Currency selection
    public string $displayCurrency = '';

    // Coupon
    public string $couponCode = '';

    public ?int $appliedCouponId = null;

    public string $couponError = '';

    public string $couponSuccess = '';

    // Processing state
    public bool $processing = false;

    public string $error = '';

    // Idempotency key for preventing duplicate orders
    public string $idempotencyKey = '';

    protected CommerceService $commerce;

    protected CouponService $couponService;

    protected TaxService $taxService;

    protected CheckoutRateLimiter $rateLimiter;

    protected CurrencyService $currencyService;

    public function boot(
        CommerceService $commerce,
        CouponService $couponService,
        TaxService $taxService,
        CheckoutRateLimiter $rateLimiter,
        CurrencyService $currencyService
    ): void {
        $this->commerce = $commerce;
        $this->couponService = $couponService;
        $this->taxService = $taxService;
        $this->rateLimiter = $rateLimiter;
        $this->currencyService = $currencyService;
    }

    public function mount(string $package = ''): void
    {
        // Generate idempotency key for this checkout session
        $this->idempotencyKey = $this->generateIdempotencyKey();

        // Detect and set display currency
        $this->displayCurrency = $this->currencyService->getCurrentCurrency();

        // Pre-select package from URL route parameter or query param
        $packageCode = $package ?: $this->plan;
        if ($packageCode) {
            $pkg = Package::where('code', $packageCode)->active()->public()->first();
            if ($pkg) {
                $this->selectedPackageId = $pkg->id;
                $this->plan = $packageCode;
            }
        }

        // Set billing cycle from URL
        if (in_array($this->cycle, ['monthly', 'yearly'])) {
            $this->billingCycle = $this->cycle;
        }

        // Pre-fill billing details if user is logged in
        if (Auth::check()) {
            $user = Auth::user();
            $workspace = $user->defaultHostWorkspace();

            if ($workspace) {
                $this->billingName = $workspace->billing_name ?? $user->name ?? '';
                $this->billingEmail = $workspace->billing_email ?? $user->email ?? '';
                $this->billingAddressLine1 = $workspace->billing_address_line1 ?? '';
                $this->billingAddressLine2 = $workspace->billing_address_line2 ?? '';
                $this->billingCity = $workspace->billing_city ?? '';
                $this->billingState = $workspace->billing_state ?? '';
                $this->billingPostalCode = $workspace->billing_postal_code ?? '';
                $this->billingCountry = $workspace->billing_country ?? 'GB';
                $this->taxId = $workspace->tax_id ?? '';
            }
        }
    }

    /**
     * Handle currency change event from CurrencySelector component.
     */
    #[On('currency-changed')]
    public function onCurrencyChanged(string $currency): void
    {
        $this->displayCurrency = $currency;
    }

    /**
     * Get the base currency.
     */
    #[Computed]
    public function baseCurrency(): string
    {
        return config('commerce.currencies.base', 'GBP');
    }

    /**
     * Get the exchange rate for display currency.
     */
    #[Computed]
    public function exchangeRate(): float
    {
        if ($this->displayCurrency === $this->baseCurrency) {
            return 1.0;
        }

        return ExchangeRate::getRate($this->baseCurrency, $this->displayCurrency) ?? 1.0;
    }

    /**
     * Get supported currencies for display.
     */
    #[Computed]
    public function supportedCurrencies(): array
    {
        return $this->currencyService->getSupportedCurrencies();
    }

    #[Computed]
    public function packages(): Collection
    {
        return Package::active()
            ->public()
            ->base()
            ->purchasable()
            ->ordered()
            ->get();
    }

    #[Computed]
    public function selectedPackage(): ?Package
    {
        if (! $this->selectedPackageId) {
            return null;
        }

        return Package::find($this->selectedPackageId);
    }

    #[Computed]
    public function appliedCoupon(): ?Coupon
    {
        if (! $this->appliedCouponId) {
            return null;
        }

        return Coupon::find($this->appliedCouponId);
    }

    /**
     * Get subtotal in base currency.
     */
    #[Computed]
    public function baseSubtotal(): float
    {
        if (! $this->selectedPackage) {
            return 0;
        }

        return $this->selectedPackage->getPrice($this->billingCycle);
    }

    /**
     * Get subtotal in display currency.
     */
    #[Computed]
    public function subtotal(): float
    {
        return $this->convertToDisplayCurrency($this->baseSubtotal);
    }

    /**
     * Get setup fee in base currency.
     */
    #[Computed]
    public function baseSetupFee(): float
    {
        if (! $this->selectedPackage) {
            return 0;
        }

        return $this->selectedPackage->setup_fee ?? 0;
    }

    /**
     * Get setup fee in display currency.
     */
    #[Computed]
    public function setupFee(): float
    {
        return $this->convertToDisplayCurrency($this->baseSetupFee);
    }

    /**
     * Get discount in base currency.
     */
    #[Computed]
    public function baseDiscount(): float
    {
        if (! $this->appliedCoupon || ! $this->selectedPackage) {
            return 0;
        }

        return $this->appliedCoupon->calculateDiscount($this->baseSubtotal);
    }

    /**
     * Get discount in display currency.
     */
    #[Computed]
    public function discount(): float
    {
        return $this->convertToDisplayCurrency($this->baseDiscount);
    }

    /**
     * Get taxable amount in base currency.
     */
    #[Computed]
    public function baseTaxableAmount(): float
    {
        return $this->baseSubtotal - $this->baseDiscount + $this->baseSetupFee;
    }

    /**
     * Get taxable amount in display currency.
     */
    #[Computed]
    public function taxableAmount(): float
    {
        return $this->subtotal - $this->discount + $this->setupFee;
    }

    /**
     * Get tax amount in base currency (tax is calculated on base amounts).
     */
    #[Computed]
    public function baseTaxAmount(): float
    {
        // Create a temporary workspace-like object for tax calculation
        $workspace = new Workspace([
            'billing_country' => $this->billingCountry,
            'billing_state' => $this->billingState,
            'tax_id' => $this->taxId,
            'tax_exempt' => false,
        ]);

        $result = $this->taxService->calculate($workspace, $this->baseTaxableAmount);

        return $result->taxAmount;
    }

    /**
     * Get tax amount in display currency.
     */
    #[Computed]
    public function taxAmount(): float
    {
        return $this->convertToDisplayCurrency($this->baseTaxAmount);
    }

    #[Computed]
    public function taxRate(): float
    {
        $workspace = new Workspace([
            'billing_country' => $this->billingCountry,
            'billing_state' => $this->billingState,
            'tax_id' => $this->taxId,
            'tax_exempt' => false,
        ]);

        $result = $this->taxService->calculate($workspace, $this->baseTaxableAmount);

        return $result->taxRate;
    }

    /**
     * Get total in base currency.
     */
    #[Computed]
    public function baseTotal(): float
    {
        return $this->baseTaxableAmount + $this->baseTaxAmount;
    }

    /**
     * Get total in display currency.
     */
    #[Computed]
    public function total(): float
    {
        return $this->taxableAmount + $this->taxAmount;
    }

    /**
     * Convert an amount from base currency to display currency.
     */
    public function convertToDisplayCurrency(float $amount): float
    {
        if ($this->displayCurrency === $this->baseCurrency) {
            return $amount;
        }

        return round($amount * $this->exchangeRate, 2);
    }

    /**
     * Format an amount in the display currency.
     */
    public function formatAmount(float $amount): string
    {
        return $this->currencyService->format($amount, $this->displayCurrency);
    }

    #[Computed]
    public function countries(): array
    {
        return [
            'GB' => 'United Kingdom',
            'US' => 'United States',
            'AU' => 'Australia',
            'AT' => 'Austria',
            'BE' => 'Belgium',
            'BG' => 'Bulgaria',
            'CA' => 'Canada',
            'HR' => 'Croatia',
            'CY' => 'Cyprus',
            'CZ' => 'Czech Republic',
            'DK' => 'Denmark',
            'EE' => 'Estonia',
            'FI' => 'Finland',
            'FR' => 'France',
            'DE' => 'Germany',
            'GR' => 'Greece',
            'HU' => 'Hungary',
            'IE' => 'Ireland',
            'IT' => 'Italy',
            'LV' => 'Latvia',
            'LT' => 'Lithuania',
            'LU' => 'Luxembourg',
            'MT' => 'Malta',
            'NL' => 'Netherlands',
            'NZ' => 'New Zealand',
            'PL' => 'Poland',
            'PT' => 'Portugal',
            'RO' => 'Romania',
            'SK' => 'Slovakia',
            'SI' => 'Slovenia',
            'ES' => 'Spain',
            'SE' => 'Sweden',
        ];
    }

    public function selectPackage(int $packageId): void
    {
        $this->selectedPackageId = $packageId;
        $this->step = 2;

        // Revalidate coupon for new package
        if ($this->appliedCouponId) {
            $this->validateAppliedCoupon();
        }
    }

    public function setBillingCycle(string $cycle): void
    {
        $this->billingCycle = $cycle;
    }

    public function applyCoupon(): void
    {
        $this->couponError = '';
        $this->couponSuccess = '';

        if (empty($this->couponCode)) {
            $this->couponError = 'Please enter a coupon code';

            return;
        }

        // Check rate limit to prevent brute-forcing coupon codes
        $userId = Auth::id();
        $workspaceId = Auth::check() ? Auth::user()->defaultHostWorkspace()?->id : null;

        if ($this->rateLimiter->tooManyCouponAttempts($workspaceId, $userId, request())) {
            $availableIn = $this->rateLimiter->couponAvailableIn($workspaceId, $userId, request());
            $minutes = ceil($availableIn / 60);
            $this->couponError = "Too many attempts. Please try again in {$minutes} minute(s).";

            return;
        }

        // Increment counter before validation
        $this->rateLimiter->incrementCoupon($workspaceId, $userId, request());

        $workspace = $this->getOrCreateWorkspace();
        $result = $this->couponService->validateByCode(
            $this->couponCode,
            $workspace,
            $this->selectedPackage
        );

        if (! $result->isValid()) {
            $this->couponError = $result->error;

            return;
        }

        $this->appliedCouponId = $result->coupon->id;
        $this->couponSuccess = "Coupon applied: {$this->commerce->formatMoney($this->discount)} off";
    }

    public function removeCoupon(): void
    {
        $this->appliedCouponId = null;
        $this->couponCode = '';
        $this->couponError = '';
        $this->couponSuccess = '';
    }

    protected function validateAppliedCoupon(): void
    {
        if (! $this->appliedCouponId) {
            return;
        }

        $coupon = Coupon::find($this->appliedCouponId);
        if (! $coupon || ! $coupon->appliesToPackage($this->selectedPackageId)) {
            $this->removeCoupon();
            $this->couponError = 'Coupon does not apply to the selected plan';
        }
    }

    public function goToStep(int $step): void
    {
        if ($step === 1 || ($step === 2 && $this->selectedPackageId)) {
            $this->step = $step;
        }
    }

    public function proceedToPayment(): void
    {
        $this->validate([
            'billingName' => 'required|string|max:255',
            'billingEmail' => 'required|email|max:255',
            'billingAddressLine1' => 'required|string|max:255',
            'billingCity' => 'required|string|max:255',
            'billingPostalCode' => 'required|string|max:20',
            'billingCountry' => 'required|string|size:2',
        ]);

        $this->step = 3;
    }

    public function checkout(string $gateway = 'btcpay'): void
    {
        $this->error = '';
        $this->processing = true;

        try {
            // Validate required fields
            if (! $this->selectedPackageId) {
                throw new \Exception('Please select a plan');
            }

            // Check rate limit before processing
            $userId = Auth::id();
            $workspaceId = Auth::check() ? Auth::user()->defaultHostWorkspace()?->id : null;

            if ($this->rateLimiter->tooManyAttempts($workspaceId, $userId, request())) {
                $availableIn = $this->rateLimiter->availableIn($workspaceId, $userId, request());
                $minutes = ceil($availableIn / 60);
                throw new \Exception("Too many checkout attempts. Please try again in {$minutes} minute(s).");
            }

            // Increment rate limiter before processing
            $this->rateLimiter->increment($workspaceId, $userId, request());

            // Get or create workspace
            $workspace = $this->getOrCreateWorkspace();

            // Update workspace billing details
            $workspace->update([
                'billing_name' => $this->billingName,
                'billing_email' => $this->billingEmail,
                'billing_address_line1' => $this->billingAddressLine1,
                'billing_address_line2' => $this->billingAddressLine2,
                'billing_city' => $this->billingCity,
                'billing_state' => $this->billingState,
                'billing_postal_code' => $this->billingPostalCode,
                'billing_country' => $this->billingCountry,
                'tax_id' => $this->taxId,
            ]);

            // Create order with idempotency key to prevent duplicates
            $order = $this->commerce->createOrder(
                $workspace,
                $this->selectedPackage,
                $this->billingCycle,
                $this->appliedCoupon,
                [
                    'display_currency' => $this->displayCurrency,
                    'exchange_rate' => $this->exchangeRate,
                ],
                $this->idempotencyKey
            );

            // Update order with multi-currency fields
            $baseCurrency = $this->baseCurrency;
            if ($this->displayCurrency !== $baseCurrency) {
                $order->update([
                    'display_currency' => $this->displayCurrency,
                    'exchange_rate_used' => $this->exchangeRate,
                    'base_currency_total' => $this->baseTotal,
                ]);
            }

            // Create checkout session
            $checkout = $this->commerce->createCheckout($order, $gateway);

            // Redirect to payment
            $this->redirect($checkout['checkout_url']);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            $this->processing = false;
        }
    }

    protected function getOrCreateWorkspace(): Workspace
    {
        if (Auth::check()) {
            $workspace = Auth::user()->defaultHostWorkspace();
            if ($workspace) {
                return $workspace;
            }
        }

        // For guest checkout, create a temporary workspace
        // This will be properly assigned when user registers/logs in
        return Workspace::create([
            'name' => $this->billingName ?: 'New Workspace',
            'slug' => 'checkout-'.uniqid(),
            'billing_email' => $this->billingEmail,
            'is_active' => false, // Activated after payment
        ]);
    }

    public function render()
    {
        return view('commerce::web.checkout.checkout-page');
    }

    /**
     * Generate a unique idempotency key for this checkout session.
     *
     * Key is based on user/session, package, billing cycle, and timestamp
     * to ensure uniqueness while allowing retries within the same session.
     */
    protected function generateIdempotencyKey(): string
    {
        $userId = Auth::id() ?? session()->getId();
        $timestamp = now()->format('YmdHi'); // Minute precision

        return hash('sha256', "{$userId}:{$timestamp}:".uniqid('', true));
    }
}
