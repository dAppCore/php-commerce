<?php

namespace Core\Mod\Commerce\View\Modal\Web;

use Core\Mod\Commerce\Models\Subscription;
use Core\Mod\Commerce\Services\CommerceService;
use Core\Mod\Commerce\Services\SubscriptionService;
use Core\Tenant\Models\Package;
use Core\Tenant\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Plan change UI for upgrading or downgrading subscriptions.
 */
#[Layout('hub::admin.layouts.app')]
class ChangePlan extends Component
{
    public ?Workspace $workspace = null;

    public ?Subscription $currentSubscription = null;

    public ?Package $currentPackage = null;

    public Collection $availablePackages;

    public ?string $selectedPackageCode = null;

    public string $billingCycle = 'monthly';

    // Preview data
    public bool $showPreview = false;

    public ?array $previewData = null;

    public bool $isLoading = false;

    public ?string $errorMessage = null;

    protected CommerceService $commerce;

    protected SubscriptionService $subscriptions;

    public function boot(CommerceService $commerce, SubscriptionService $subscriptions): void
    {
        $this->commerce = $commerce;
        $this->subscriptions = $subscriptions;
    }

    public function mount(): void
    {
        $this->workspace = Auth::user()?->defaultHostWorkspace();

        if (! $this->workspace) {
            $this->availablePackages = collect();

            return;
        }

        $this->loadData();
    }

    protected function loadData(): void
    {
        // Load current subscription
        $this->currentSubscription = $this->workspace->subscriptions()
            ->active()
            ->with('workspacePackage.package')
            ->latest()
            ->first();

        if ($this->currentSubscription) {
            $this->currentPackage = $this->currentSubscription->workspacePackage?->package;
            $this->billingCycle = $this->guessBillingCycle();
        }

        // Load available packages (public ones only)
        $this->availablePackages = Package::query()
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderBy('monthly_price', 'asc')
            ->get();
    }

    protected function guessBillingCycle(): string
    {
        if (! $this->currentSubscription) {
            return 'monthly';
        }

        $periodDays = $this->currentSubscription->current_period_start
            ?->diffInDays($this->currentSubscription->current_period_end);

        return ($periodDays ?? 30) > 32 ? 'yearly' : 'monthly';
    }

    public function setBillingCycle(string $cycle): void
    {
        $this->billingCycle = $cycle;
        $this->resetPreview();
    }

    public function selectPackage(string $packageCode): void
    {
        $this->selectedPackageCode = $packageCode;
        $this->resetPreview();
    }

    public function resetPreview(): void
    {
        $this->showPreview = false;
        $this->previewData = null;
        $this->errorMessage = null;
    }

    public function preview(): void
    {
        if (! $this->selectedPackageCode || ! $this->currentSubscription) {
            return;
        }

        $this->isLoading = true;
        $this->errorMessage = null;

        try {
            $newPackage = Package::where('code', $this->selectedPackageCode)->first();

            if (! $newPackage) {
                $this->errorMessage = 'Selected package not found.';

                return;
            }

            $proration = $this->subscriptions->previewPlanChange(
                $this->currentSubscription,
                $newPackage,
                $this->billingCycle
            );

            $this->previewData = [
                'current_plan' => $this->currentPackage?->name ?? 'Current Plan',
                'new_plan' => $newPackage->name,
                'current_price' => $proration->currentPlanPrice,
                'new_price' => $proration->newPlanPrice,
                'proration_amount' => $proration->netAmount,
                'effective_date' => now()->format('j F Y'),
                'next_billing_amount' => $proration->newPlanPrice,
                'is_upgrade' => $proration->isUpgrade(),
            ];

            $this->showPreview = true;
        } catch (\Exception $e) {
            $this->errorMessage = 'Unable to preview plan change: '.$e->getMessage();
        } finally {
            $this->isLoading = false;
        }
    }

    public function confirmChange(): void
    {
        if (! $this->selectedPackageCode || ! $this->currentSubscription) {
            return;
        }

        $this->isLoading = true;
        $this->errorMessage = null;

        try {
            $newPackage = Package::where('code', $this->selectedPackageCode)->first();

            if (! $newPackage) {
                $this->errorMessage = 'Selected package not found.';
                $this->isLoading = false;

                return;
            }

            $this->subscriptions->changePlan(
                $this->currentSubscription,
                $newPackage,
                prorate: true
            );

            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Your plan has been updated successfully.',
            ]);

            // Redirect to subscription page
            $this->redirect(route('hub.billing.subscription'), navigate: true);
        } catch (\Exception $e) {
            $this->errorMessage = 'Unable to change plan: '.$e->getMessage();
            $this->isLoading = false;
        }
    }

    public function formatMoney(float $amount): string
    {
        return $this->commerce->formatMoney($amount);
    }

    public function isCurrentPackage(Package $package): bool
    {
        return $this->currentPackage?->id === $package->id;
    }

    public function render()
    {
        return view('commerce::web.change-plan');
    }
}
