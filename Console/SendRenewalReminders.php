<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Console;

use Core\Mod\Commerce\Models\Subscription;
use Core\Mod\Commerce\Notifications\UpcomingRenewal;
use Core\Mod\Commerce\Services\CommerceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendRenewalReminders extends Command
{
    protected $signature = 'commerce:renewal-reminders
                            {--days=7 : Days before renewal to send reminder}
                            {--dry-run : Show what would happen without sending}';

    protected $description = 'Send renewal reminder emails to customers with upcoming subscription renewals';

    public function __construct(
        protected CommerceService $commerce
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('commerce.notifications.upcoming_renewal', true)) {
            $this->info('Renewal reminder notifications are disabled.');

            return self::SUCCESS;
        }

        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No emails will be sent');
        }

        $this->info("Finding subscriptions renewing in {$days} days...");

        // Find subscriptions renewing soon that haven't been reminded
        $subscriptions = Subscription::query()
            ->active()
            ->whereNull('cancelled_at')
            ->where('current_period_end', '>', now())
            ->where('current_period_end', '<=', now()->addDays($days))
            ->whereDoesntHave('metadata', function ($query) use ($days) {
                // Skip if already reminded for this period
                $query->where('last_renewal_reminder', '>=', now()->subDays($days));
            })
            ->with(['workspace', 'workspacePackage.package'])
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->info('No subscriptions require reminders.');

            return self::SUCCESS;
        }

        $this->info("Found {$subscriptions->count()} subscriptions to remind.");
        $sent = 0;

        foreach ($subscriptions as $subscription) {
            $owner = $subscription->workspace?->owner();

            if (! $owner) {
                $this->warn("  Skipping subscription {$subscription->id} - no workspace owner");

                continue;
            }

            $package = $subscription->workspacePackage?->package;
            $billingCycle = $this->guessBillingCycle($subscription);
            $amount = $package?->getPrice($billingCycle) ?? 0;

            $this->line("  Sending reminder to {$owner->email} for subscription {$subscription->id}...");

            if ($dryRun) {
                $sent++;

                continue;
            }

            try {
                $owner->notify(new UpcomingRenewal(
                    $subscription,
                    $amount,
                    config('commerce.currency', 'GBP')
                ));

                // Record that we sent the reminder
                $subscription->update([
                    'metadata' => array_merge($subscription->metadata ?? [], [
                        'last_renewal_reminder' => now()->toISOString(),
                    ]),
                ]);

                $sent++;
                $this->info('    ✓ Sent');
            } catch (\Exception $e) {
                $this->error("    ✗ Failed: {$e->getMessage()}");
                Log::error('Renewal reminder failed', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info("Sent {$sent} renewal reminders.");

        return self::SUCCESS;
    }

    protected function guessBillingCycle(Subscription $subscription): string
    {
        $periodDays = $subscription->current_period_start
            ?->diffInDays($subscription->current_period_end);

        return ($periodDays ?? 30) > 32 ? 'yearly' : 'monthly';
    }
}
