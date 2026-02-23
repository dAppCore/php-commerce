<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Console;

use Core\Mod\Commerce\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Mod\Trees\Models\TreePlanting;

/**
 * Plants trees for active subscribers.
 *
 * Part of the Trees for Agents programme. Subscribers get:
 * - 1 tree/month for Starter/Pro/Creator/Agency plans
 * - 2 trees/month for Enterprise plans
 *
 * This command is idempotent - running multiple times in the same month
 * will not create duplicate tree plantings.
 */
class PlantSubscriberTrees extends Command
{
    protected $signature = 'trees:subscriber-monthly
                            {--dry-run : Show what would be planted without actually planting}
                            {--force : Ignore monthly check and plant regardless}';

    protected $description = 'Plant monthly trees for active subscribers';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $month = now()->format('Y-m');

        $this->info("Trees for Agents: Monthly subscriber planting for {$month}");
        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No trees will actually be planted');
            $this->newLine();
        }

        // Get all active subscriptions
        $subscriptions = Subscription::query()
            ->active()
            ->with(['workspace', 'workspacePackage.package'])
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->info('No active subscriptions found.');

            return self::SUCCESS;
        }

        $this->info("Found {$subscriptions->count()} active subscriptions");
        $this->newLine();

        $planted = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($subscriptions as $subscription) {
            $result = $this->processSubscription($subscription, $month, $dryRun, $force);

            match ($result) {
                'planted' => $planted++,
                'skipped' => $skipped++,
                'error' => $errors++,
            };
        }

        $this->newLine();
        $this->table(
            ['Status', 'Count'],
            [
                ['Planted', $planted],
                ['Skipped (already planted)', $skipped],
                ['Errors', $errors],
            ]
        );

        if ($dryRun) {
            $this->newLine();
            $this->warn('DRY RUN COMPLETE - No trees were actually planted');
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Process a single subscription for tree planting.
     */
    protected function processSubscription(
        Subscription $subscription,
        string $month,
        bool $dryRun,
        bool $force
    ): string {
        $workspace = $subscription->workspace;

        if (! $workspace) {
            $this->error("  [ERROR] Subscription #{$subscription->id} has no workspace");

            return 'error';
        }

        // Check if already planted this month (idempotency)
        if (! $force && $this->hasPlantedThisMonth($workspace->id, $month)) {
            $this->line("  [SKIP] {$workspace->name} - already planted in {$month}");

            return 'skipped';
        }

        // Determine tree count based on package tier
        $trees = $this->getTreeCountForSubscription($subscription);
        $packageName = $this->getPackageName($subscription);

        if ($dryRun) {
            $this->info("  [DRY RUN] Would plant {$trees} tree(s) for {$workspace->name} ({$packageName})");

            return 'planted';
        }

        // Create the tree planting record
        $planting = TreePlanting::create([
            'provider' => null,
            'model' => null,
            'source' => TreePlanting::SOURCE_SUBSCRIPTION,
            'trees' => $trees,
            'user_id' => null,
            'workspace_id' => $workspace->id,
            'status' => TreePlanting::STATUS_PENDING,
            'metadata' => [
                'subscription_id' => $subscription->id,
                'package' => $packageName,
                'month' => $month,
            ],
        ]);

        // Confirm the tree immediately
        $planting->markConfirmed();

        Log::info('Subscriber monthly tree planted', [
            'tree_planting_id' => $planting->id,
            'workspace_id' => $workspace->id,
            'workspace_name' => $workspace->name,
            'trees' => $trees,
            'package' => $packageName,
            'month' => $month,
        ]);

        $this->info("  [PLANTED] {$trees} tree(s) for {$workspace->name} ({$packageName})");

        return 'planted';
    }

    /**
     * Check if this workspace has already had trees planted this month.
     */
    protected function hasPlantedThisMonth(int $workspaceId, string $month): bool
    {
        // Parse the month string (YYYY-MM format)
        $date = \Carbon\Carbon::createFromFormat('Y-m', $month);
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        return TreePlanting::query()
            ->where('workspace_id', $workspaceId)
            ->where('source', TreePlanting::SOURCE_SUBSCRIPTION)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->exists();
    }

    /**
     * Get the number of trees for this subscription tier.
     *
     * Enterprise: 2 trees/month
     * All others: 1 tree/month
     */
    protected function getTreeCountForSubscription(Subscription $subscription): int
    {
        $packageCode = $subscription->workspacePackage?->package?->code ?? '';

        // Enterprise packages get 2 trees
        if (str_contains(strtolower($packageCode), 'enterprise')) {
            return 2;
        }

        return 1;
    }

    /**
     * Get the package name for display.
     */
    protected function getPackageName(Subscription $subscription): string
    {
        return $subscription->workspacePackage?->package?->name
            ?? $subscription->workspacePackage?->package?->code
            ?? 'Unknown';
    }
}
