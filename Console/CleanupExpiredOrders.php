<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Console;

use Core\Mod\Commerce\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupExpiredOrders extends Command
{
    protected $signature = 'commerce:cleanup-orders
                            {--dry-run : Show what would happen without making changes}
                            {--ttl= : Override session TTL in minutes (default from config)}';

    protected $description = 'Cancel pending orders older than the checkout session TTL';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $ttlMinutes = $this->option('ttl') ?? config('commerce.checkout.session_ttl', 30);

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $this->info("Cleaning up pending orders older than {$ttlMinutes} minutes...");

        $cutoffTime = now()->subMinutes((int) $ttlMinutes);

        // Find pending orders older than the TTL
        $query = Order::where('status', 'pending')
            ->where('created_at', '<', $cutoffTime);

        $count = $query->count();

        if ($count === 0) {
            $this->info('No expired orders to clean up.');

            return self::SUCCESS;
        }

        $this->info("Found {$count} expired pending order(s).");

        if ($dryRun) {
            $this->table(
                ['Order Number', 'Created At', 'Total'],
                $query->get()->map(fn ($order) => [
                    $order->order_number,
                    $order->created_at->format('Y-m-d H:i:s'),
                    $order->total,
                ])->toArray()
            );

            return self::SUCCESS;
        }

        // Cancel expired orders
        $cancelled = 0;
        $failed = 0;

        $query->chunk(100, function ($orders) use (&$cancelled, &$failed) {
            foreach ($orders as $order) {
                try {
                    $order->cancel();
                    $cancelled++;

                    Log::info('Expired order cancelled', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'created_at' => $order->created_at->toIso8601String(),
                    ]);
                } catch (\Exception $e) {
                    $failed++;

                    Log::error('Failed to cancel expired order', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        $this->info("Cancelled {$cancelled} expired order(s).");

        if ($failed > 0) {
            $this->warn("Failed to cancel {$failed} order(s). Check logs for details.");
        }

        Log::info('Expired order cleanup completed', [
            'cancelled' => $cancelled,
            'failed' => $failed,
            'ttl_minutes' => $ttlMinutes,
        ]);

        return self::SUCCESS;
    }
}
