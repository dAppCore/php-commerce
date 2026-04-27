<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Console;

use Core\Mod\Commerce\Models\Subscription;
use Core\Mod\Commerce\Services\UsageBillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sync usage records to Stripe metered billing.
 *
 * Run periodically to ensure usage is reported to Stripe
 * for metered billing invoices.
 */
class SyncUsageToStripe extends Command
{
    protected $signature = 'commerce:sync-usage
                            {--subscription= : Sync only a specific subscription ID}
                            {--dry-run : Show what would be synced without making changes}';

    protected $description = 'Sync usage records to Stripe metered billing API';

    public function __construct(
        protected UsageBillingService $usageBilling
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('commerce.features.usage_billing', false)) {
            $this->info('Usage billing is disabled.');

            return self::SUCCESS;
        }

        if (! config('commerce.usage_billing.sync_to_stripe', true)) {
            $this->info('Stripe sync is disabled.');

            return self::SUCCESS;
        }

        $dryRun = $this->option('dry-run');
        $subscriptionId = $this->option('subscription');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $this->info('Syncing usage to Stripe...');
        $this->newLine();

        // Get subscriptions to sync
        $query = Subscription::query()
            ->where('gateway', 'stripe')
            ->whereNotNull('gateway_subscription_id')
            ->active();

        if ($subscriptionId) {
            $query->where('id', $subscriptionId);
        }

        $subscriptions = $query->get();

        if ($subscriptions->isEmpty()) {
            $this->info('No Stripe subscriptions found to sync.');

            return self::SUCCESS;
        }

        $this->info("Found {$subscriptions->count()} subscription(s) to sync.");
        $this->newLine();

        $totalSynced = 0;
        $errors = 0;

        $this->withProgressBar($subscriptions, function (Subscription $subscription) use ($dryRun, &$totalSynced, &$errors) {
            if ($dryRun) {
                // Count unsynced usage for preview
                $unsynced = $subscription->usageRecords()
                    ->whereNull('synced_at')
                    ->where('quantity', '>', 0)
                    ->count();
                $totalSynced += $unsynced;

                return;
            }

            try {
                $synced = $this->usageBilling->syncToStripe($subscription);
                $totalSynced += $synced;
            } catch (\Exception $e) {
                $errors++;
                Log::error('Failed to sync usage to Stripe', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        $this->newLine(2);

        if ($dryRun) {
            $this->info("Would sync {$totalSynced} usage record(s).");
        } else {
            $this->info("Synced {$totalSynced} usage record(s) to Stripe.");

            if ($errors > 0) {
                $this->warn("{$errors} subscription(s) had sync errors. Check logs for details.");
            }
        }

        Log::info('Usage sync completed', [
            'synced' => $totalSynced,
            'errors' => $errors,
            'dry_run' => $dryRun,
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
