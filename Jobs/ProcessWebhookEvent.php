<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Jobs;

use Core\Mod\Commerce\Models\WebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class ProcessWebhookEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $webhookEventId,
    ) {}

    public function handle(): void
    {
        $webhookEvent = WebhookEvent::find($this->webhookEventId);

        if (! $webhookEvent || ! $webhookEvent->isPending()) {
            return;
        }

        try {
            Event::dispatch(
                "commerce.webhook.{$webhookEvent->gateway}.{$webhookEvent->event_type}",
                [$webhookEvent, $webhookEvent->getDecodedPayload()]
            );

            $webhookEvent->markProcessed();
        } catch (\Throwable $e) {
            $webhookEvent->markFailed($e->getMessage());

            Log::error('Queued webhook event processing failed', [
                'webhook_event_id' => $webhookEvent->id,
                'gateway' => $webhookEvent->gateway,
                'event_type' => $webhookEvent->event_type,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
