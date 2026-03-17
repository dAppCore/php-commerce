<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Carbon\Carbon;
use Core\Mod\Commerce\Models\Invoice;
use Core\Mod\Commerce\Models\InvoiceItem;
use Core\Mod\Commerce\Models\Subscription;
use Core\Mod\Commerce\Models\SubscriptionUsage;
use Core\Mod\Commerce\Models\UsageEvent;
use Core\Mod\Commerce\Models\UsageMeter;
use Core\Mod\Commerce\Services\PaymentGateway\StripeGateway;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

/**
 * Usage-based billing service.
 *
 * Records usage events, aggregates usage per billing period,
 * and integrates with Stripe metered billing API.
 */
class UsageBillingService
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected TaxService $taxService,
    ) {}

    // -------------------------------------------------------------------------
    // Usage Recording
    // -------------------------------------------------------------------------

    /**
     * Record a usage event for a subscription.
     */
    public function recordUsage(
        Subscription $subscription,
        string $meterCode,
        int $quantity = 1,
        ?User $user = null,
        ?string $action = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null
    ): ?UsageEvent {
        if (! config('commerce.features.usage_billing', false)) {
            return null;
        }

        $meter = UsageMeter::findByCode($meterCode);

        if (! $meter || ! $meter->is_active) {
            Log::warning('Usage meter not found or inactive', [
                'meter_code' => $meterCode,
                'subscription_id' => $subscription->id,
            ]);

            return null;
        }

        return DB::transaction(function () use ($subscription, $meter, $quantity, $user, $action, $metadata, $idempotencyKey) {
            // Create usage event
            $event = UsageEvent::createWithIdempotency([
                'subscription_id' => $subscription->id,
                'meter_id' => $meter->id,
                'workspace_id' => $subscription->workspace_id,
                'quantity' => $quantity,
                'event_at' => now(),
                'idempotency_key' => $idempotencyKey,
                'user_id' => $user?->id,
                'action' => $action,
                'metadata' => $metadata,
            ]);

            if (! $event) {
                Log::info('Duplicate usage event skipped', [
                    'idempotency_key' => $idempotencyKey,
                ]);

                return null;
            }

            // Update aggregated usage for current period
            $usage = SubscriptionUsage::getOrCreateForCurrentPeriod($subscription, $meter);
            $usage->addQuantity($quantity);

            Log::debug('Usage recorded', [
                'subscription_id' => $subscription->id,
                'meter_code' => $meter->code,
                'quantity' => $quantity,
                'period_total' => $usage->quantity,
            ]);

            return $event;
        });
    }

    /**
     * Record usage for a workspace (finds active subscription automatically).
     */
    public function recordUsageForWorkspace(
        Workspace $workspace,
        string $meterCode,
        int $quantity = 1,
        ?User $user = null,
        ?string $action = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null
    ): ?UsageEvent {
        $subscription = $workspace->subscriptions()
            ->active()
            ->first();

        if (! $subscription) {
            Log::debug('No active subscription for usage recording', [
                'workspace_id' => $workspace->id,
                'meter_code' => $meterCode,
            ]);

            return null;
        }

        return $this->recordUsage(
            $subscription,
            $meterCode,
            $quantity,
            $user,
            $action,
            $metadata,
            $idempotencyKey
        );
    }

    // -------------------------------------------------------------------------
    // Usage Retrieval
    // -------------------------------------------------------------------------

    /**
     * Get current period usage for a subscription.
     */
    public function getCurrentUsage(Subscription $subscription, ?string $meterCode = null): Collection
    {
        $query = SubscriptionUsage::query()
            ->with('meter')
            ->where('subscription_id', $subscription->id)
            ->where('period_start', '>=', $subscription->current_period_start)
            ->where('period_end', '<=', $subscription->current_period_end);

        if ($meterCode) {
            $meter = UsageMeter::findByCode($meterCode);
            if ($meter) {
                $query->where('meter_id', $meter->id);
            }
        }

        return $query->get();
    }

    /**
     * Get usage summary for display.
     */
    public function getUsageSummary(Subscription $subscription): array
    {
        $usage = $this->getCurrentUsage($subscription);

        return $usage->map(function (SubscriptionUsage $record) {
            return [
                'meter_code' => $record->meter->code,
                'meter_name' => $record->meter->name,
                'quantity' => $record->quantity,
                'unit_label' => $record->meter->unit_label,
                'estimated_charge' => $record->calculateCharge(),
                'currency' => $record->meter->currency,
                'period_start' => $record->period_start->toISOString(),
                'period_end' => $record->period_end->toISOString(),
            ];
        })->values()->all();
    }

    /**
     * Get usage history for a subscription.
     */
    public function getUsageHistory(
        Subscription $subscription,
        ?string $meterCode = null,
        int $periods = 6
    ): Collection {
        $query = SubscriptionUsage::query()
            ->with('meter')
            ->where('subscription_id', $subscription->id)
            ->orderByDesc('period_start');

        if ($meterCode) {
            $meter = UsageMeter::findByCode($meterCode);
            if ($meter) {
                $query->where('meter_id', $meter->id);
            }
        }

        return $query->limit($periods)->get();
    }

    // -------------------------------------------------------------------------
    // Billing & Invoicing
    // -------------------------------------------------------------------------

    /**
     * Calculate charges for unbilled usage.
     */
    public function calculatePendingCharges(Subscription $subscription): float
    {
        $usage = SubscriptionUsage::query()
            ->with('meter')
            ->where('subscription_id', $subscription->id)
            ->where('billed', false)
            ->where('period_end', '<=', now())
            ->get();

        return $usage->sum(fn (SubscriptionUsage $record) => $record->calculateCharge());
    }

    /**
     * Create invoice line items for usage charges.
     */
    public function createUsageLineItems(Invoice $invoice, Subscription $subscription): Collection
    {
        $usage = SubscriptionUsage::query()
            ->with('meter')
            ->where('subscription_id', $subscription->id)
            ->where('billed', false)
            ->where('period_end', '<=', now())
            ->get();

        $lineItems = collect();

        foreach ($usage as $record) {
            $charge = $record->calculateCharge();

            if ($charge <= 0) {
                continue;
            }

            $description = sprintf(
                '%s: %s %s (%s - %s)',
                $record->meter->name,
                number_format($record->quantity),
                $record->meter->unit_label,
                $record->period_start->format('d M'),
                $record->period_end->format('d M Y')
            );

            $invoiceItem = InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => $description,
                'quantity' => 1,
                'unit_price' => $charge,
                'line_total' => $charge,
                'taxable' => true,
                'metadata' => [
                    'type' => 'usage',
                    'meter_code' => $record->meter->code,
                    'usage_quantity' => $record->quantity,
                    'period_start' => $record->period_start->toISOString(),
                    'period_end' => $record->period_end->toISOString(),
                ],
            ]);

            $record->markBilled($invoiceItem->id);
            $lineItems->push($invoiceItem);
        }

        return $lineItems;
    }

    // -------------------------------------------------------------------------
    // Stripe Integration
    // -------------------------------------------------------------------------

    /**
     * Sync usage to Stripe metered billing.
     */
    public function syncToStripe(Subscription $subscription): int
    {
        if ($subscription->gateway !== 'stripe' || ! $subscription->gateway_subscription_id) {
            return 0;
        }

        $gateway = app('commerce.gateway.stripe');

        if (! $gateway instanceof StripeGateway || ! $gateway->isEnabled()) {
            return 0;
        }

        $unsyncedUsage = SubscriptionUsage::query()
            ->with('meter')
            ->where('subscription_id', $subscription->id)
            ->whereNull('synced_at')
            ->whereNotNull('quantity')
            ->where('quantity', '>', 0)
            ->get();

        $synced = 0;

        foreach ($unsyncedUsage as $usage) {
            if (! $usage->meter->stripe_price_id) {
                continue;
            }

            try {
                $this->reportStripeUsage($gateway, $subscription, $usage);
                $synced++;
            } catch (\Exception $e) {
                Log::error('Failed to sync usage to Stripe', [
                    'subscription_id' => $subscription->id,
                    'usage_id' => $usage->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $synced;
    }

    /**
     * Report usage to Stripe for a single usage record.
     */
    protected function reportStripeUsage(
        StripeGateway $gateway,
        Subscription $subscription,
        SubscriptionUsage $usage
    ): void {
        $stripe = new StripeClient(config('commerce.gateways.stripe.secret'));

        // Find the subscription item for this price
        $stripeSubscription = $stripe->subscriptions->retrieve(
            $subscription->gateway_subscription_id,
            ['expand' => ['items']]
        );

        $subscriptionItem = null;
        foreach ($stripeSubscription->items->data as $item) {
            if ($item->price->id === $usage->meter->stripe_price_id) {
                $subscriptionItem = $item;
                break;
            }
        }

        if (! $subscriptionItem) {
            Log::warning('Stripe subscription item not found for meter', [
                'subscription_id' => $subscription->id,
                'stripe_price_id' => $usage->meter->stripe_price_id,
            ]);

            return;
        }

        // Report usage
        $usageRecord = $stripe->subscriptionItems->createUsageRecord(
            $subscriptionItem->id,
            [
                'quantity' => $usage->quantity,
                'timestamp' => $usage->period_end->getTimestamp(),
                'action' => 'set', // 'set' replaces, 'increment' adds
            ]
        );

        $usage->markSynced($usageRecord->id);

        Log::info('Usage synced to Stripe', [
            'subscription_id' => $subscription->id,
            'meter_code' => $usage->meter->code,
            'quantity' => $usage->quantity,
            'stripe_usage_record_id' => $usageRecord->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Meter Management
    // -------------------------------------------------------------------------

    /**
     * Get all active meters.
     */
    public function getActiveMeters(): Collection
    {
        return UsageMeter::active()->orderBy('name')->get();
    }

    /**
     * Create a new meter.
     */
    public function createMeter(array $data): UsageMeter
    {
        return UsageMeter::create($data);
    }

    /**
     * Update a meter.
     */
    public function updateMeter(UsageMeter $meter, array $data): UsageMeter
    {
        $meter->update($data);

        return $meter->fresh();
    }

    /**
     * Sync a meter to Stripe (create meter and price in Stripe).
     */
    public function syncMeterToStripe(UsageMeter $meter): ?string
    {
        $secret = config('commerce.gateways.stripe.secret');

        if (! $secret) {
            return null;
        }

        $stripe = new StripeClient($secret);

        // Create or update product in Stripe
        $product = $stripe->products->create([
            'name' => $meter->name,
            'description' => $meter->description,
            'metadata' => [
                'meter_code' => $meter->code,
                'type' => 'metered',
            ],
        ]);

        // Create metered price
        $price = $stripe->prices->create([
            'product' => $product->id,
            'currency' => strtolower($meter->currency),
            'recurring' => [
                'interval' => 'month',
                'usage_type' => 'metered',
                'aggregate_usage' => $meter->aggregation_type === UsageMeter::AGGREGATION_MAX ? 'max' : 'sum',
            ],
            'unit_amount_decimal' => (string) ($meter->unit_price * 100),
            'billing_scheme' => $meter->hasTieredPricing() ? 'tiered' : 'per_unit',
        ]);

        $meter->update([
            'stripe_price_id' => $price->id,
        ]);

        return $price->id;
    }

    // -------------------------------------------------------------------------
    // Period Management
    // -------------------------------------------------------------------------

    /**
     * Reset usage for a new billing period.
     *
     * Called when subscription renews.
     */
    public function onPeriodReset(Subscription $subscription): void
    {
        $meters = UsageMeter::active()->get();

        foreach ($meters as $meter) {
            // Create fresh usage record for new period
            SubscriptionUsage::create([
                'subscription_id' => $subscription->id,
                'meter_id' => $meter->id,
                'quantity' => 0,
                'period_start' => $subscription->current_period_start,
                'period_end' => $subscription->current_period_end,
            ]);
        }

        Log::info('Usage reset for new period', [
            'subscription_id' => $subscription->id,
            'period_start' => $subscription->current_period_start,
        ]);
    }

    /**
     * Aggregate usage events into subscription usage records.
     *
     * Useful for batch processing or reconciliation.
     */
    public function aggregateUsage(
        Subscription $subscription,
        Carbon $periodStart,
        Carbon $periodEnd
    ): Collection {
        $meters = UsageMeter::active()->get();
        $results = collect();

        foreach ($meters as $meter) {
            $totalQuantity = UsageEvent::getTotalQuantity(
                $subscription->id,
                $meter->id,
                $periodStart,
                $periodEnd
            );

            $usage = SubscriptionUsage::updateOrCreate(
                [
                    'subscription_id' => $subscription->id,
                    'meter_id' => $meter->id,
                    'period_start' => $periodStart,
                ],
                [
                    'quantity' => $totalQuantity,
                    'period_end' => $periodEnd,
                ]
            );

            $results->push($usage);
        }

        return $results;
    }
}
