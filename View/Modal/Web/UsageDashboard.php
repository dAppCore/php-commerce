<?php

namespace Core\Mod\Commerce\View\Modal\Web;

use Core\Mod\Commerce\Models\Subscription;
use Core\Mod\Commerce\Services\CommerceService;
use Core\Mod\Commerce\Services\UsageBillingService;
use Core\Tenant\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Usage Dashboard component.
 *
 * Displays current billing period usage consumption and estimated charges.
 */
class UsageDashboard extends Component
{
    public ?Workspace $workspace = null;

    public ?Subscription $activeSubscription = null;

    public Collection $currentUsage;

    public Collection $usageHistory;

    public float $estimatedCharges = 0;

    public ?string $periodStart = null;

    public ?string $periodEnd = null;

    public int $daysRemaining = 0;

    public bool $usageBillingEnabled = false;

    protected CommerceService $commerce;

    protected UsageBillingService $usageBilling;

    public function boot(CommerceService $commerce, UsageBillingService $usageBilling): void
    {
        $this->commerce = $commerce;
        $this->usageBilling = $usageBilling;
    }

    public function mount(): void
    {
        $this->usageBillingEnabled = config('commerce.features.usage_billing', false);
        $this->currentUsage = collect();
        $this->usageHistory = collect();

        if (! $this->usageBillingEnabled) {
            return;
        }

        $this->workspace = Auth::user()?->defaultHostWorkspace();

        if (! $this->workspace) {
            return;
        }

        // Load active subscription
        $this->activeSubscription = $this->workspace->subscriptions()
            ->active()
            ->with('workspacePackage.package')
            ->latest()
            ->first();

        if (! $this->activeSubscription) {
            return;
        }

        $this->loadUsageData();
    }

    protected function loadUsageData(): void
    {
        // Get current period info
        $this->periodStart = $this->activeSubscription->current_period_start?->format('j M Y');
        $this->periodEnd = $this->activeSubscription->current_period_end?->format('j M Y');
        $this->daysRemaining = $this->activeSubscription->daysUntilRenewal();

        // Get current usage summary
        $usageSummary = $this->usageBilling->getUsageSummary($this->activeSubscription);
        $this->currentUsage = collect($usageSummary);

        // Calculate estimated charges
        $this->estimatedCharges = $this->currentUsage->sum('estimated_charge');

        // Get usage history (last 6 periods)
        $this->usageHistory = $this->usageBilling->getUsageHistory(
            $this->activeSubscription,
            null,
            6
        )->groupBy(fn ($usage) => $usage->period_start->format('Y-m'));
    }

    public function formatMoney(float $amount, ?string $currency = null): string
    {
        $currency = $currency ?? config('commerce.currency', 'GBP');
        $symbol = match ($currency) {
            'GBP' => '£',
            'USD' => '$',
            'EUR' => '€',
            default => $currency.' ',
        };

        return $symbol.number_format($amount, 2);
    }

    public function formatNumber(int $value): string
    {
        return number_format($value);
    }

    /**
     * Get usage percentage for a specific meter.
     *
     * If meter has included quota from subscription package,
     * calculate percentage used.
     */
    public function getUsagePercentage(array $usage, ?int $includedQuota = null): ?int
    {
        if ($includedQuota === null || $includedQuota <= 0) {
            return null;
        }

        return min(100, (int) round(($usage['quantity'] / $includedQuota) * 100));
    }

    /**
     * Get status colour based on usage percentage.
     */
    public function getUsageStatusColour(?int $percentage): string
    {
        if ($percentage === null) {
            return 'zinc';
        }

        return match (true) {
            $percentage >= 100 => 'red',
            $percentage >= 90 => 'amber',
            $percentage >= 75 => 'yellow',
            default => 'emerald',
        };
    }

    public function refresh(): void
    {
        $this->loadUsageData();
    }

    public function render()
    {
        return view('commerce::web.usage-dashboard')
            ->layout('hub::admin.layouts.app', ['title' => 'Usage']);
    }
}
