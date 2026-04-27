<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\View\Modal\Web;

use Core\Mod\Commerce\Models\Subscription as SubscriptionModel;
use Core\Mod\Commerce\Notifications\SubscriptionCancelled;
use Core\Mod\Commerce\Services\CommerceService;
use Core\Mod\Commerce\Services\SubscriptionService;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('hub::admin.layouts.app')]
class Subscription extends Component
{
    public ?Workspace $workspace = null;

    public ?SubscriptionModel $activeSubscription = null;

    public Collection $subscriptionHistory;

    public string $currentPlan = 'Free';

    public string $billingCycle = 'monthly';

    public ?string $nextBillingDate = null;

    public float $nextBillingAmount = 0;

    public bool $showCancelModal = false;

    public string $cancelReason = '';

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
            $this->subscriptionHistory = collect();

            return;
        }

        $this->loadSubscriptionData();
    }

    protected function loadSubscriptionData(): void
    {
        // Load active subscription
        $this->activeSubscription = $this->workspace->subscriptions()
            ->active()
            ->with('workspacePackage.package')
            ->latest()
            ->first();

        if ($this->activeSubscription) {
            $this->currentPlan = $this->activeSubscription->workspacePackage?->package?->name ?? 'Subscription';
            $this->nextBillingDate = $this->activeSubscription->current_period_end?->format('j F Y');
            $this->billingCycle = $this->guessBillingCycle();

            $package = $this->activeSubscription->workspacePackage?->package;
            if ($package) {
                $this->nextBillingAmount = $package->getPrice($this->billingCycle);
            }
        }

        // Load subscription history
        $this->subscriptionHistory = $this->workspace->subscriptions()
            ->with('workspacePackage.package')
            ->latest()
            ->limit(10)
            ->get();
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

    public function openCancelModal(): void
    {
        $this->showCancelModal = true;
    }

    public function closeCancelModal(): void
    {
        $this->showCancelModal = false;
        $this->cancelReason = '';
    }

    public function cancelSubscription(): void
    {
        if (! $this->activeSubscription) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'No active subscription to cancel.',
            ]);

            return;
        }

        try {
            $this->subscriptions->cancel($this->activeSubscription, $this->cancelReason);

            // Notify user
            $user = Auth::user();
            if ($user instanceof User) {
                $user->notify(new SubscriptionCancelled($this->activeSubscription));
            }

            $this->closeCancelModal();
            $this->loadSubscriptionData();

            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Your subscription has been scheduled for cancellation at the end of the current billing period.',
            ]);
        } catch (\Exception $e) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Failed to cancel subscription. Please contact support.',
            ]);
        }
    }

    public function resumeSubscription(): void
    {
        if (! $this->activeSubscription || ! $this->activeSubscription->cancelled_at) {
            return;
        }

        try {
            $this->subscriptions->resume($this->activeSubscription);

            $this->loadSubscriptionData();

            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Your subscription has been resumed.',
            ]);
        } catch (\Exception $e) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Failed to resume subscription. Please contact support.',
            ]);
        }
    }

    public function formatMoney(float $amount): string
    {
        return $this->commerce->formatMoney($amount);
    }

    public function render()
    {
        return view('commerce::web.subscription');
    }
}
