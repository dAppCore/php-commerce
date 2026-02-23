<?php

namespace Core\Mod\Commerce\View\Modal\Web;

use Core\Mod\Commerce\Models\PaymentMethod;
use Core\Mod\Commerce\Services\PaymentGateway\StripeGateway;
use Core\Mod\Commerce\Services\PaymentMethodService;
use Core\Tenant\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('hub::admin.layouts.app')]
class PaymentMethods extends Component
{
    public ?Workspace $workspace = null;

    public Collection $paymentMethods;

    public ?PaymentMethod $defaultMethod = null;

    public bool $isAddingMethod = false;

    public ?string $stripePortalUrl = null;

    /** @var array<int, bool> Payment methods expiring soon */
    public array $expiringMethods = [];

    protected StripeGateway $stripeGateway;

    protected PaymentMethodService $paymentMethodService;

    public function boot(StripeGateway $stripeGateway, PaymentMethodService $paymentMethodService): void
    {
        $this->stripeGateway = $stripeGateway;
        $this->paymentMethodService = $paymentMethodService;
    }

    public function mount(): void
    {
        $this->workspace = Auth::user()?->defaultHostWorkspace();
        $this->loadPaymentMethods();

        // Check for setup_intent query param (return from Stripe setup)
        $setupIntent = request()->query('setup_intent');
        if ($setupIntent) {
            $this->handleSetupReturn($setupIntent);
        }
    }

    public function loadPaymentMethods(): void
    {
        if (! $this->workspace) {
            $this->paymentMethods = collect();

            return;
        }

        $this->paymentMethods = $this->paymentMethodService->getPaymentMethods($this->workspace);
        $this->defaultMethod = $this->paymentMethods->firstWhere('is_default', true);

        // Check for expiring cards
        $this->expiringMethods = [];
        foreach ($this->paymentMethods as $method) {
            if ($this->paymentMethodService->isExpiringSoon($method)) {
                $this->expiringMethods[$method->id] = true;
            }
        }
    }

    public function setDefault(PaymentMethod $paymentMethod): void
    {
        if ($paymentMethod->workspace_id !== $this->workspace?->id) {
            return;
        }

        try {
            $this->paymentMethodService->setDefaultPaymentMethod($this->workspace, $paymentMethod);

            $this->loadPaymentMethods();

            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Default payment method updated.',
            ]);
        } catch (\Exception $e) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Failed to update default payment method.',
            ]);
        }
    }

    public function removeMethod(PaymentMethod $paymentMethod): void
    {
        if ($paymentMethod->workspace_id !== $this->workspace?->id) {
            return;
        }

        try {
            $this->paymentMethodService->removePaymentMethod($this->workspace, $paymentMethod);

            $this->loadPaymentMethods();

            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Payment method removed.',
            ]);
        } catch (\RuntimeException $e) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Failed to remove payment method.',
            ]);
        }
    }

    /**
     * Start adding a new payment method via Stripe Setup Session.
     */
    public function addPaymentMethod(): void
    {
        if (! $this->workspace) {
            return;
        }

        // Check if Stripe is enabled
        if (! $this->stripeGateway->isEnabled()) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Card payments are not currently available. Please try again later.',
            ]);

            return;
        }

        $this->isAddingMethod = true;

        try {
            $session = $this->paymentMethodService->createSetupSession(
                $this->workspace,
                route('hub.billing.payment-methods')
            );

            // Redirect to Stripe's hosted setup page
            $this->redirect($session['setup_url']);
        } catch (\Exception $e) {
            $this->isAddingMethod = false;
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Unable to start payment method setup. Please try again.',
            ]);
        }
    }

    /**
     * Open Stripe's billing portal for full payment management.
     */
    public function openStripePortal(): void
    {
        if (! $this->workspace) {
            return;
        }

        if (! $this->stripeGateway->isEnabled()) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Card payments are not currently available.',
            ]);

            return;
        }

        try {
            $url = $this->paymentMethodService->getBillingPortalUrl(
                $this->workspace,
                route('hub.billing.payment-methods')
            );

            if ($url) {
                $this->redirect($url);
            } else {
                $this->dispatch('notify', [
                    'type' => 'error',
                    'message' => 'No billing account found. Please add a payment method first.',
                ]);
            }
        } catch (\Exception $e) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Unable to open billing portal. Please try again.',
            ]);
        }
    }

    /**
     * Handle the return from Stripe setup session.
     */
    public function handleSetupReturn(?string $setupIntent = null): void
    {
        if (! $setupIntent || ! $this->workspace) {
            return;
        }

        try {
            // The setup intent ID can be used to retrieve the payment method
            // For now, just reload the payment methods (Stripe webhook will have processed it)
            $this->loadPaymentMethods();

            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Payment method added successfully.',
            ]);
        } catch (\Exception $e) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Unable to confirm payment method. Please try again.',
            ]);
        }
    }

    /**
     * Check if a payment method is expiring soon.
     */
    public function isExpiringSoon(int $methodId): bool
    {
        return $this->expiringMethods[$methodId] ?? false;
    }

    public function render()
    {
        return view('commerce::web.payment-methods');
    }
}
