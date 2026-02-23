<?php

namespace Core\Mod\Commerce\Services;

use Core\Mod\Commerce\Models\PaymentMethod;
use Core\Mod\Commerce\Services\PaymentGateway\StripeGateway;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing payment methods.
 *
 * Handles adding, removing, and managing saved payment methods
 * for workspaces with full gateway integration.
 */
class PaymentMethodService
{
    public function __construct(
        protected StripeGateway $stripeGateway,
    ) {}

    /**
     * Get all active payment methods for a workspace.
     */
    public function getPaymentMethods(Workspace $workspace): Collection
    {
        return $workspace->paymentMethods()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Get the default payment method for a workspace.
     */
    public function getDefaultPaymentMethod(Workspace $workspace): ?PaymentMethod
    {
        return $workspace->paymentMethods()
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();
    }

    /**
     * Add a new payment method to a workspace.
     *
     * @param  string  $gatewayPaymentMethodId  The payment method ID from the gateway (e.g., pm_xxx for Stripe)
     */
    public function addPaymentMethod(
        Workspace $workspace,
        string $gatewayPaymentMethodId,
        ?User $user = null,
        string $gateway = 'stripe'
    ): PaymentMethod {
        return DB::transaction(function () use ($workspace, $gatewayPaymentMethodId, $user, $gateway) {
            // For Stripe, attach and get details from the gateway
            if ($gateway === 'stripe') {
                return $this->addStripePaymentMethod($workspace, $gatewayPaymentMethodId, $user);
            }

            throw new \InvalidArgumentException("Unsupported payment gateway: {$gateway}");
        });
    }

    /**
     * Add a Stripe payment method.
     */
    protected function addStripePaymentMethod(
        Workspace $workspace,
        string $gatewayPaymentMethodId,
        ?User $user = null
    ): PaymentMethod {
        // Check if payment method already exists
        $existing = PaymentMethod::where('workspace_id', $workspace->id)
            ->where('gateway', 'stripe')
            ->where('gateway_payment_method_id', $gatewayPaymentMethodId)
            ->first();

        if ($existing) {
            // Reactivate if it was deactivated
            if (! $existing->is_active) {
                $existing->update(['is_active' => true]);
            }

            return $existing;
        }

        // Attach to Stripe customer
        $paymentMethod = $this->stripeGateway->attachPaymentMethod($workspace, $gatewayPaymentMethodId);

        // Update with user info
        if ($user) {
            $paymentMethod->update(['user_id' => $user->id]);
        }

        // If this is the first payment method, make it the default
        $hasOtherMethods = $workspace->paymentMethods()
            ->where('is_active', true)
            ->where('id', '!=', $paymentMethod->id)
            ->exists();

        if (! $hasOtherMethods) {
            $this->setDefaultPaymentMethod($workspace, $paymentMethod);
        }

        Log::info('Payment method added', [
            'workspace_id' => $workspace->id,
            'payment_method_id' => $paymentMethod->id,
            'type' => $paymentMethod->type,
            'brand' => $paymentMethod->brand,
        ]);

        return $paymentMethod;
    }

    /**
     * Remove a payment method from a workspace.
     *
     * @throws \RuntimeException If the payment method cannot be removed
     */
    public function removePaymentMethod(Workspace $workspace, PaymentMethod $paymentMethod): void
    {
        // Verify ownership
        if ($paymentMethod->workspace_id !== $workspace->id) {
            throw new \RuntimeException('Payment method does not belong to this workspace.');
        }

        // Check if this is the last active payment method
        $activeCount = $workspace->paymentMethods()
            ->where('is_active', true)
            ->count();

        if ($activeCount === 1) {
            // Check for active subscriptions
            $hasActiveSubscription = $workspace->subscriptions()
                ->active()
                ->exists();

            if ($hasActiveSubscription) {
                throw new \RuntimeException(
                    'Cannot remove the last payment method while you have an active subscription.'
                );
            }
        }

        DB::transaction(function () use ($workspace, $paymentMethod) {
            // Detach from gateway (Stripe)
            if ($paymentMethod->gateway === 'stripe' && $paymentMethod->gateway_payment_method_id) {
                try {
                    $this->stripeGateway->detachPaymentMethod($paymentMethod);
                } catch (\Exception $e) {
                    Log::warning('Failed to detach payment method from Stripe', [
                        'payment_method_id' => $paymentMethod->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with local removal even if gateway fails
                }
            }

            // If this was the default, make another one the default
            if ($paymentMethod->is_default) {
                $newDefault = $workspace->paymentMethods()
                    ->where('is_active', true)
                    ->where('id', '!=', $paymentMethod->id)
                    ->first();

                if ($newDefault) {
                    $this->setDefaultPaymentMethod($workspace, $newDefault);
                }
            }

            // Soft-delete by marking as inactive
            $paymentMethod->update(['is_active' => false]);

            Log::info('Payment method removed', [
                'workspace_id' => $workspace->id,
                'payment_method_id' => $paymentMethod->id,
            ]);
        });
    }

    /**
     * Set a payment method as the default for a workspace.
     */
    public function setDefaultPaymentMethod(Workspace $workspace, PaymentMethod $paymentMethod): void
    {
        // Verify ownership
        if ($paymentMethod->workspace_id !== $workspace->id) {
            throw new \RuntimeException('Payment method does not belong to this workspace.');
        }

        DB::transaction(function () use ($workspace, $paymentMethod) {
            // Update gateway default (for Stripe)
            if ($paymentMethod->gateway === 'stripe') {
                try {
                    $this->stripeGateway->setDefaultPaymentMethod($paymentMethod);
                } catch (\Exception $e) {
                    Log::warning('Failed to set default payment method in Stripe', [
                        'payment_method_id' => $paymentMethod->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with local update even if gateway fails
                }
            }

            // Remove default from all other methods
            $workspace->paymentMethods()
                ->where('id', '!=', $paymentMethod->id)
                ->update(['is_default' => false]);

            // Set this one as default
            $paymentMethod->update(['is_default' => true]);

            Log::info('Default payment method updated', [
                'workspace_id' => $workspace->id,
                'payment_method_id' => $paymentMethod->id,
            ]);
        });
    }

    /**
     * Sync payment methods from Stripe to local database.
     *
     * This is useful when payment methods are added via Stripe's
     * hosted checkout or customer portal.
     */
    public function syncPaymentMethodsFromStripe(Workspace $workspace): Collection
    {
        if (! $workspace->stripe_customer_id) {
            return collect();
        }

        // This would need the Stripe SDK to list payment methods
        // For now, we rely on webhooks to keep data in sync
        Log::info('Payment method sync requested', [
            'workspace_id' => $workspace->id,
            'stripe_customer_id' => $workspace->stripe_customer_id,
        ]);

        return $this->getPaymentMethods($workspace);
    }

    /**
     * Check if a payment method is expiring soon.
     */
    public function isExpiringSoon(PaymentMethod $paymentMethod, int $monthsThreshold = 2): bool
    {
        if (! $paymentMethod->exp_month || ! $paymentMethod->exp_year) {
            return false;
        }

        $expiry = \Carbon\Carbon::createFromDate(
            $paymentMethod->exp_year,
            $paymentMethod->exp_month
        )->endOfMonth();

        return $expiry->isBefore(now()->addMonths($monthsThreshold));
    }

    /**
     * Get all workspaces with expiring payment methods.
     *
     * Useful for sending expiry warning notifications.
     */
    public function getExpiringPaymentMethods(int $monthsThreshold = 2): Collection
    {
        $thresholdDate = now()->addMonths($monthsThreshold);

        return PaymentMethod::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->where('type', 'card')
            ->whereNotNull('exp_month')
            ->whereNotNull('exp_year')
            ->whereRaw("STR_TO_DATE(CONCAT(exp_year, '-', exp_month, '-01'), '%Y-%m-%d') <= ?", [
                $thresholdDate->format('Y-m-d'),
            ])
            ->with('workspace')
            ->get();
    }

    /**
     * Update payment method details from gateway.
     *
     * Called when card details are updated (e.g., new expiry date from card networks).
     */
    public function updateFromGateway(PaymentMethod $paymentMethod, array $gatewayData): PaymentMethod
    {
        $updates = [];

        if (isset($gatewayData['card'])) {
            $card = $gatewayData['card'];
            $updates['brand'] = $card['brand'] ?? $paymentMethod->brand;
            $updates['last_four'] = $card['last4'] ?? $paymentMethod->last_four;
            $updates['exp_month'] = $card['exp_month'] ?? $paymentMethod->exp_month;
            $updates['exp_year'] = $card['exp_year'] ?? $paymentMethod->exp_year;
        }

        if (! empty($updates)) {
            $paymentMethod->update($updates);
        }

        return $paymentMethod->fresh();
    }

    /**
     * Create a setup session for adding a payment method.
     *
     * Returns the URL to redirect the user to Stripe's hosted setup page.
     */
    public function createSetupSession(Workspace $workspace, string $returnUrl): array
    {
        if (! $this->stripeGateway->isEnabled()) {
            throw new \RuntimeException('Stripe payments are not currently available.');
        }

        return $this->stripeGateway->createSetupSession($workspace, $returnUrl);
    }

    /**
     * Get the billing portal URL for full payment management.
     */
    public function getBillingPortalUrl(Workspace $workspace, string $returnUrl): ?string
    {
        if (! $this->stripeGateway->isEnabled()) {
            return null;
        }

        return $this->stripeGateway->getPortalUrl($workspace, $returnUrl);
    }
}
