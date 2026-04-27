<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\View\Modal\Web;

use Core\Mod\Commerce\Models\Subscription;
use Core\Mod\Commerce\Services\CommerceService;
use Core\Tenant\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Dashboard extends Component
{
    public ?Workspace $workspace = null;

    public ?Subscription $activeSubscription = null;

    public Collection $recentInvoices;

    public Collection $upcomingCharges;

    public ?string $nextBillingDate = null;

    public float $nextBillingAmount = 0;

    public string $currentPlan = 'Free';

    public string $billingCycle = 'monthly';

    public int $treesPlanted = 0;

    public int $treesThisYear = 0;

    protected CommerceService $commerce;

    public function boot(CommerceService $commerce): void
    {
        $this->commerce = $commerce;
    }

    public function mount(): void
    {
        $this->workspace = Auth::user()?->defaultHostWorkspace();

        if (! $this->workspace) {
            $this->recentInvoices = collect();
            $this->upcomingCharges = collect();

            return;
        }

        // Load active subscription
        $this->activeSubscription = $this->workspace->subscriptions()
            ->active()
            ->with('workspacePackage.package')
            ->latest()
            ->first();

        if ($this->activeSubscription) {
            $this->currentPlan = $this->activeSubscription->workspacePackage?->package?->name ?? 'Subscription';
            $this->nextBillingDate = $this->activeSubscription->current_period_end?->format('j M Y');
            $this->billingCycle = $this->guessBillingCycle();

            // Calculate next billing amount
            $package = $this->activeSubscription->workspacePackage?->package;
            if ($package) {
                $this->nextBillingAmount = $package->getPrice($this->billingCycle);
            }
        }

        // Load recent invoices
        $this->recentInvoices = $this->workspace->invoices()
            ->with('items')
            ->latest()
            ->limit(5)
            ->get();

        // Calculate upcoming charges (subscriptions renewing soon)
        $this->upcomingCharges = $this->workspace->subscriptions()
            ->valid()
            ->expiringSoon(30)
            ->with('workspacePackage.package')
            ->get();

        // Load tree planting stats (Trees for Agents programme)
        $this->treesPlanted = $this->workspace->treesPlanted();
        $this->treesThisYear = $this->workspace->treesThisYear();
    }

    public function formatMoney(float $amount): string
    {
        return $this->commerce->formatMoney($amount);
    }

    protected function guessBillingCycle(): string
    {
        if (! $this->activeSubscription) {
            return 'monthly';
        }

        $periodDays = $this->activeSubscription->current_period_start
            ?->diffInDays($this->activeSubscription->current_period_end);

        return ($periodDays ?? 30) > 32 ? 'yearly' : 'monthly';
    }

    public function render()
    {
        return view('commerce::web.dashboard')
            ->layout('hub::admin.layouts.app', ['title' => 'Billing']);
    }
}
