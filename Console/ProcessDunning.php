<?php

namespace Core\Mod\Commerce\Console;

use Core\Mod\Commerce\Services\DunningService;
use Core\Mod\Commerce\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessDunning extends Command
{
    protected $signature = 'commerce:process-dunning
                            {--dry-run : Show what would happen without making changes}
                            {--stage= : Process only a specific stage (retry, pause, suspend, cancel, expire)}';

    protected $description = 'Process dunning for failed payments - retry charges, pause, suspend, and cancel subscriptions';

    public function __construct(
        protected DunningService $dunning,
        protected SubscriptionService $subscriptions
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('commerce.dunning.enabled', true)) {
            $this->info('Dunning is disabled.');

            return self::SUCCESS;
        }

        $dryRun = $this->option('dry-run');
        $stage = $this->option('stage');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $this->info('Processing dunning...');
        $this->newLine();

        $results = [
            'retried' => 0,
            'paused' => 0,
            'suspended' => 0,
            'cancelled' => 0,
            'expired' => 0,
        ];

        // Process stages based on option or all
        if (! $stage || $stage === 'retry') {
            $results['retried'] = $this->processRetries($dryRun);
        }

        if (! $stage || $stage === 'pause') {
            $results['paused'] = $this->processPauses($dryRun);
        }

        if (! $stage || $stage === 'suspend') {
            $results['suspended'] = $this->processSuspensions($dryRun);
        }

        if (! $stage || $stage === 'cancel') {
            $results['cancelled'] = $this->processCancellations($dryRun);
        }

        if (! $stage || $stage === 'expire') {
            $results['expired'] = $this->processExpired($dryRun);
        }

        $this->newLine();
        $this->info('Dunning Summary:');
        $this->table(
            ['Action', 'Count'],
            [
                ['Payment retries attempted', $results['retried']],
                ['Subscriptions paused', $results['paused']],
                ['Workspaces suspended', $results['suspended']],
                ['Subscriptions cancelled', $results['cancelled']],
                ['Subscriptions expired', $results['expired']],
            ]
        );

        Log::info('Dunning process completed', $results);

        return self::SUCCESS;
    }

    /**
     * Process payment retries for overdue invoices.
     */
    protected function processRetries(bool $dryRun): int
    {
        $this->info('Stage 1: Payment Retries');
        $invoices = $this->dunning->getInvoicesDueForRetry();

        if ($invoices->isEmpty()) {
            $this->line('  No invoices due for retry');

            return 0;
        }

        $count = 0;

        foreach ($invoices as $invoice) {
            $this->line("  Processing invoice {$invoice->invoice_number}...");

            if ($dryRun) {
                $this->comment("    Would retry payment (attempt {$invoice->charge_attempts})");
                $count++;

                continue;
            }

            try {
                $success = $this->dunning->retryPayment($invoice);

                if ($success) {
                    $this->info('    Payment successful');
                } else {
                    $this->warn('    Payment failed - next retry scheduled');
                }

                $count++;
            } catch (\Exception $e) {
                $this->error("    Error: {$e->getMessage()}");
                Log::error('Dunning retry failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Process subscription pauses (after max retries exhausted).
     */
    protected function processPauses(bool $dryRun): int
    {
        $this->info('Stage 2: Subscription Pauses');
        $subscriptions = $this->dunning->getSubscriptionsForPause();

        if ($subscriptions->isEmpty()) {
            $this->line('  No subscriptions to pause');

            return 0;
        }

        $count = 0;

        foreach ($subscriptions as $subscription) {
            $this->line("  Pausing subscription {$subscription->id} (workspace {$subscription->workspace_id})...");

            if ($dryRun) {
                $this->comment('    Would pause subscription');
                $count++;

                continue;
            }

            try {
                $this->dunning->pauseSubscription($subscription);
                $this->info('    Subscription paused');
                $count++;
            } catch (\Exception $e) {
                $this->error("    Error: {$e->getMessage()}");
                Log::error('Dunning pause failed', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Process workspace suspensions.
     */
    protected function processSuspensions(bool $dryRun): int
    {
        $this->info('Stage 3: Workspace Suspensions');
        $subscriptions = $this->dunning->getSubscriptionsForSuspension();

        if ($subscriptions->isEmpty()) {
            $this->line('  No workspaces to suspend');

            return 0;
        }

        $count = 0;

        foreach ($subscriptions as $subscription) {
            $this->line("  Suspending workspace {$subscription->workspace_id}...");

            if ($dryRun) {
                $this->comment('    Would suspend workspace entitlements');
                $count++;

                continue;
            }

            try {
                $this->dunning->suspendWorkspace($subscription);
                $this->info('    Workspace suspended');
                $count++;
            } catch (\Exception $e) {
                $this->error("    Error: {$e->getMessage()}");
                Log::error('Dunning suspension failed', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Process subscription cancellations.
     */
    protected function processCancellations(bool $dryRun): int
    {
        $this->info('Stage 4: Subscription Cancellations');
        $subscriptions = $this->dunning->getSubscriptionsForCancellation();

        if ($subscriptions->isEmpty()) {
            $this->line('  No subscriptions to cancel');

            return 0;
        }

        $count = 0;

        foreach ($subscriptions as $subscription) {
            $this->line("  Cancelling subscription {$subscription->id}...");

            if ($dryRun) {
                $this->comment('    Would cancel subscription due to non-payment');
                $count++;

                continue;
            }

            try {
                $this->dunning->cancelSubscription($subscription);
                $this->info('    Subscription cancelled');
                $count++;
            } catch (\Exception $e) {
                $this->error("    Error: {$e->getMessage()}");
                Log::error('Dunning cancellation failed', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Process expired subscriptions (cancelled with period ended).
     */
    protected function processExpired(bool $dryRun): int
    {
        $this->info('Stage 5: Expired Subscriptions');

        if ($dryRun) {
            $count = \Core\Mod\Commerce\Models\Subscription::query()
                ->active()
                ->whereNotNull('cancelled_at')
                ->where('current_period_end', '<=', now())
                ->count();

            $this->line("  Would expire {$count} subscriptions");

            return $count;
        }

        $expired = $this->subscriptions->processExpired();
        $this->line("  Expired {$expired} subscriptions");

        return $expired;
    }
}
