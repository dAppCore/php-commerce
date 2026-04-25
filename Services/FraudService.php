<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Core\Mod\Commerce\Data\FraudAssessment;
use Core\Mod\Commerce\Data\FraudScore;
use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\Payment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Fraud detection and scoring service.
 *
 * Integrates with Stripe Radar for card payments and provides
 * velocity-based and geo-based fraud detection for all payment types.
 */
class FraudService
{
    /**
     * Risk level constants.
     */
    public const RISK_HIGHEST = 'highest';

    public const RISK_ELEVATED = 'elevated';

    public const RISK_NORMAL = 'normal';

    public const RISK_NOT_ASSESSED = 'not_assessed';

    public const RECOMMENDATION_APPROVE = 'approve';

    public const RECOMMENDATION_REVIEW = 'review';

    public const RECOMMENDATION_BLOCK = 'block';

    public const ORDER_STATUS_PENDING_REVIEW = 'pending_review';

    private const FRAUD_REVIEW_PENDING = 'pending';

    private const FRAUD_REVIEW_APPROVED = 'approved';

    private const FRAUD_REVIEW_BLOCKED = 'blocked';

    private const MAX_REASON_LENGTH = 500;

    private const SIGNAL_WEIGHTS = [
        'velocity_ip_exceeded' => 35,
        'velocity_email_exceeded' => 25,
        'velocity_failed_exceeded' => 35,
        'geo_country_mismatch' => 20,
        'high_risk_country' => 60,
        'card_bin_country_mismatch' => 25,
        'network_declined' => 15,
    ];

    /**
     * Score an order for fraud risk.
     */
    public function score(Order $order): FraudScore
    {
        if (! config('commerce.fraud.enabled', true)) {
            return new FraudScore(
                score: 0,
                signals: [],
                recommendation: self::RECOMMENDATION_APPROVE
            );
        }

        $score = 0;
        $signals = [];

        if (config('commerce.fraud.velocity.enabled', true)) {
            $this->addSignalsToScore($signals, $score, $this->checkVelocity($order));
        }

        if (config('commerce.fraud.geo.enabled', true)) {
            $this->addSignalsToScore($signals, $score, $this->checkGeoAnomalies($order));
        }

        $this->addSignalsToScore($signals, $score, $this->checkCardBinMismatch($order));
        $score = max($score, $this->scoreStripeRadarSignals($order, $signals));
        $score = $this->clampScore($score);

        return new FraudScore(
            score: $score,
            signals: $signals,
            recommendation: $this->recommendationForScore($score)
        );
    }

    /**
     * Mark an order for manual fraud review.
     */
    public function flag(Order $order, string $reason): void
    {
        $reason = $this->normaliseReason($reason);
        $metadata = $this->metadataWithFraudState($order, [
            'review_status' => self::FRAUD_REVIEW_PENDING,
            'review_reason' => $reason,
            'previous_status' => $this->previousOrderStatus($order),
            'flagged_at' => now()->toIso8601String(),
            'approved_at' => null,
            'blocked_at' => null,
        ]);

        $order->update([
            'status' => self::ORDER_STATUS_PENDING_REVIEW,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Reject an order due to confirmed fraud.
     */
    public function block(Order $order, string $reason): void
    {
        $reason = $this->normaliseReason($reason);
        $metadata = $this->metadataWithFraudState($order, [
            'review_status' => self::FRAUD_REVIEW_BLOCKED,
            'block_reason' => $reason,
            'blocked_at' => now()->toIso8601String(),
            'failure_reason' => $reason,
        ]);

        $metadata['failure_reason'] = $reason;
        $metadata['failed_at'] = now()->toIso8601String();

        $order->update([
            'status' => 'failed',
            'metadata' => $metadata,
        ]);
    }

    /**
     * Orders waiting for manual fraud review.
     *
     * @return Collection<int, Order>
     */
    public function reviewQueue(): Collection
    {
        return Order::query()
            ->where('status', self::ORDER_STATUS_PENDING_REVIEW)
            ->oldest()
            ->get()
            ->filter(fn (Order $order): bool => data_get($order->metadata, 'fraud.review_status') === self::FRAUD_REVIEW_PENDING)
            ->values();
    }

    /**
     * Approve an order that was held for fraud review.
     */
    public function approve(Order $order): void
    {
        if (data_get($order->metadata, 'fraud.review_status') !== self::FRAUD_REVIEW_PENDING) {
            throw new RuntimeException('Only orders pending fraud review can be approved.');
        }

        $metadata = $this->metadataWithFraudState($order, [
            'review_status' => self::FRAUD_REVIEW_APPROVED,
            'approved_at' => now()->toIso8601String(),
        ]);

        $order->update([
            'status' => data_get($metadata, 'fraud.previous_status', 'pending'),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Assess fraud risk for an order before checkout.
     *
     * This performs velocity checks and geo-anomaly detection.
     * Stripe Radar assessment happens after payment attempt.
     */
    public function assessOrder(Order $order): FraudAssessment
    {
        if (! config('commerce.fraud.enabled', true)) {
            return FraudAssessment::notAssessed();
        }

        $signals = [];
        $riskLevel = self::RISK_NORMAL;

        // Velocity checks
        if (config('commerce.fraud.velocity.enabled', true)) {
            $velocitySignals = $this->checkVelocity($order);
            $signals = array_merge($signals, $velocitySignals);

            if (! empty($velocitySignals)) {
                $riskLevel = self::RISK_ELEVATED;
            }
        }

        // Geo-anomaly checks
        if (config('commerce.fraud.geo.enabled', true)) {
            $geoSignals = $this->checkGeoAnomalies($order);
            $signals = array_merge($signals, $geoSignals);

            if (! empty($geoSignals)) {
                // High-risk country = highest risk
                if (isset($geoSignals['high_risk_country'])) {
                    $riskLevel = self::RISK_HIGHEST;
                } elseif ($riskLevel !== self::RISK_HIGHEST) {
                    $riskLevel = self::RISK_ELEVATED;
                }
            }
        }

        $assessment = new FraudAssessment(
            riskLevel: $riskLevel,
            signals: $signals,
            source: 'internal',
            shouldBlock: $this->shouldBlockOrder($riskLevel),
            shouldReview: $this->shouldReviewOrder($riskLevel)
        );

        // Log and notify if configured
        $this->logAssessment($order, $assessment);

        return $assessment;
    }

    /**
     * Process fraud signals from Stripe Radar after payment.
     *
     * Called by webhook handlers when receiving payment_intent or charge events.
     */
    public function processStripeRadarOutcome(Payment $payment, array $outcome): FraudAssessment
    {
        if (! config('commerce.fraud.stripe_radar.enabled', true)) {
            return FraudAssessment::notAssessed();
        }

        $signals = [];
        $riskLevel = self::RISK_NORMAL;

        // Extract Stripe Radar risk level
        $stripeRiskLevel = $outcome['risk_level'] ?? null;
        $stripeRiskScore = $outcome['risk_score'] ?? null;
        $networkStatus = $outcome['network_status'] ?? null;
        $sellerMessage = $outcome['seller_message'] ?? null;
        $type = $outcome['type'] ?? null;

        // Map Stripe risk levels
        if ($stripeRiskLevel === 'highest') {
            $riskLevel = self::RISK_HIGHEST;
            $signals['stripe_risk_highest'] = true;
        } elseif ($stripeRiskLevel === 'elevated') {
            $riskLevel = self::RISK_ELEVATED;
            $signals['stripe_risk_elevated'] = true;
        } elseif ($stripeRiskLevel === 'normal' || $stripeRiskLevel === 'not_assessed') {
            $riskLevel = self::RISK_NORMAL;
        }

        // Add risk score if available
        if ($stripeRiskScore !== null) {
            $signals['stripe_risk_score'] = $stripeRiskScore;
        }

        // Check for specific Radar rules triggered
        if (isset($outcome['rule'])) {
            $signals['stripe_rule_triggered'] = $outcome['rule']['id'] ?? 'unknown';
            $signals['stripe_rule_action'] = $outcome['rule']['action'] ?? null;

            // Rule-based blocking overrides score
            if (($outcome['rule']['action'] ?? null) === 'block') {
                $riskLevel = self::RISK_HIGHEST;
            }
        }

        // Network status signals
        if ($networkStatus === 'declined_by_network') {
            $signals['network_declined'] = true;
        }

        $assessment = new FraudAssessment(
            riskLevel: $riskLevel,
            signals: $signals,
            source: 'stripe_radar',
            stripeRiskScore: $stripeRiskScore,
            shouldBlock: $this->shouldBlockPayment($riskLevel),
            shouldReview: $this->shouldReviewPayment($riskLevel)
        );

        // Store assessment on payment if configured
        if (config('commerce.fraud.stripe_radar.store_scores', true)) {
            $this->storeFraudAssessment($payment, $assessment);
        }

        // Log the assessment
        $this->logPaymentAssessment($payment, $assessment);

        return $assessment;
    }

    /**
     * Check velocity-based fraud signals.
     */
    protected function checkVelocity(Order $order): array
    {
        $signals = [];
        $ip = $this->getOrderIp($order);
        $email = $order->billing_email;
        $workspaceId = $this->getOrderWorkspaceId($order);

        $maxOrdersPerIpHourly = config('commerce.fraud.velocity.max_orders_per_ip_hourly', 5);
        $maxOrdersPerEmailDaily = config('commerce.fraud.velocity.max_orders_per_email_daily', 10);

        // Check orders per IP in the last hour
        if ($ip) {
            $ipKey = "fraud:orders:ip:{$ip}";
            $ipCount = (int) Cache::get($ipKey, 0);

            if ($ipCount >= $maxOrdersPerIpHourly) {
                $signals['velocity_ip_exceeded'] = [
                    'ip' => $ip,
                    'count' => $ipCount,
                    'limit' => $maxOrdersPerIpHourly,
                ];
            }

            // Increment counter (expires in 1 hour)
            Cache::put($ipKey, $ipCount + 1, now()->addHour());
        }

        // Check orders per email in the last 24 hours
        if ($email) {
            $emailKey = 'fraud:orders:email:'.hash('sha256', strtolower($email));
            $emailCount = (int) Cache::get($emailKey, 0);

            if ($emailCount >= $maxOrdersPerEmailDaily) {
                $signals['velocity_email_exceeded'] = [
                    'email_hash' => substr(hash('sha256', $email), 0, 8),
                    'count' => $emailCount,
                    'limit' => $maxOrdersPerEmailDaily,
                ];
            }

            // Increment counter (expires in 24 hours)
            Cache::put($emailKey, $emailCount + 1, now()->addDay());
        }

        // Check failed payments for this workspace in the last hour
        if ($workspaceId) {
            $failedKey = "fraud:failed:workspace:{$workspaceId}";
            $failedCount = (int) Cache::get($failedKey, 0);
            $maxFailed = config('commerce.fraud.velocity.max_failed_payments_hourly', 3);

            if ($failedCount >= $maxFailed) {
                $signals['velocity_failed_exceeded'] = [
                    'workspace_id' => $workspaceId,
                    'failed_count' => $failedCount,
                    'limit' => $maxFailed,
                ];
            }
        }

        return $signals;
    }

    /**
     * Check geo-anomaly fraud signals.
     */
    protected function checkGeoAnomalies(Order $order): array
    {
        $signals = [];
        $billingCountry = $this->getBillingCountry($order);
        $ipCountry = $this->getIpCountry($order);

        // Check for country mismatch
        if (config('commerce.fraud.geo.flag_country_mismatch', true)) {
            if ($billingCountry && $ipCountry && $billingCountry !== $ipCountry) {
                $signals['geo_country_mismatch'] = [
                    'billing_country' => $billingCountry,
                    'ip_country' => $ipCountry,
                ];
            }
        }

        // Check for high-risk countries
        $configuredHighRiskCountries = config('commerce.fraud.geo.high_risk_countries', []);
        $highRiskCountries = array_map(
            fn (mixed $country): ?string => $this->normaliseCountry($country),
            is_array($configuredHighRiskCountries) ? $configuredHighRiskCountries : []
        );
        if (! empty($highRiskCountries) && $billingCountry) {
            if (in_array($billingCountry, $highRiskCountries, true)) {
                $signals['high_risk_country'] = $billingCountry;
            }
        }

        return $signals;
    }

    /**
     * Get country code from IP address.
     */
    protected function getIpCountry(?Order $order = null): ?string
    {
        if ($order) {
            $metadata = $order->metadata ?? [];
            $metadataCountry = data_get($metadata, 'ip_country')
                ?? data_get($metadata, 'ip_country_code')
                ?? data_get($metadata, 'geo.country')
                ?? data_get($metadata, 'ip.country');

            if ($metadataCountry) {
                return $this->normaliseCountry($metadataCountry);
            }
        }

        $ip = $order ? $this->getOrderIp($order) : request()->ip();
        if (! $ip || $ip === '127.0.0.1' || str_starts_with($ip, '192.168.')) {
            return null;
        }

        // Use cached geo lookup if available
        $cacheKey = "geo:ip:{$ip}";

        return Cache::remember($cacheKey, now()->addDay(), function () use ($ip) {
            // Try to use Laravel's built-in geo detection if available
            // Otherwise, return null (geo check will be skipped)
            try {
                // This would integrate with a geo-IP service like MaxMind
                // For now, return null as a placeholder
                return null;
            } catch (\Exception $e) {
                Log::warning('Geo-IP lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * Check for card issuing country mismatch against billing country.
     */
    protected function checkCardBinMismatch(Order $order): array
    {
        $billingCountry = $this->getBillingCountry($order);
        $metadata = $order->metadata ?? [];
        $cardCountry = $this->normaliseCountry(
            data_get($metadata, 'card_bin_country')
            ?? data_get($metadata, 'card.bin_country')
            ?? data_get($metadata, 'payment_method.card_country')
            ?? data_get($metadata, 'payment_method_details.card.country')
            ?? data_get($metadata, 'stripe.payment_method_details.card.country')
        );

        if (! $billingCountry || ! $cardCountry || $billingCountry === $cardCountry) {
            return [];
        }

        return [
            'card_bin_country_mismatch' => [
                'billing_country' => $billingCountry,
                'card_country' => $cardCountry,
            ],
        ];
    }

    /**
     * Fold weighted signals into the running fraud score.
     *
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $newSignals
     */
    protected function addSignalsToScore(array &$signals, int &$score, array $newSignals): void
    {
        foreach ($newSignals as $key => $value) {
            $signals[$key] = $value;
            $score += self::SIGNAL_WEIGHTS[$key] ?? 10;
        }
    }

    /**
     * Convert Stripe Radar metadata into score and signals.
     *
     * @param  array<string, mixed>  $signals
     */
    protected function scoreStripeRadarSignals(Order $order, array &$signals): int
    {
        $radar = $this->getStripeRadarMetadata($order);

        if ($radar === []) {
            return 0;
        }

        $score = 0;
        $riskLevel = data_get($radar, 'risk_level') ?? data_get($radar, 'riskLevel');
        $riskScore = data_get($radar, 'risk_score') ?? data_get($radar, 'stripe_risk_score');

        if ($riskLevel === self::RISK_HIGHEST) {
            $signals['stripe_risk_highest'] = true;
            $score = max($score, 90);
        } elseif ($riskLevel === self::RISK_ELEVATED) {
            $signals['stripe_risk_elevated'] = true;
            $score = max($score, 60);
        }

        if (is_numeric($riskScore)) {
            $signals['stripe_risk_score'] = (int) $riskScore;
            $score = max($score, (int) $riskScore);
        }

        $ruleAction = data_get($radar, 'rule.action') ?? data_get($radar, 'stripe_rule_action');
        if ($ruleAction) {
            $signals['stripe_rule_action'] = $ruleAction;
        }

        if ($ruleAction === 'block') {
            $score = 100;
        }

        $networkStatus = data_get($radar, 'network_status');
        if ($networkStatus === 'declined_by_network') {
            $signals['network_declined'] = true;
            $score += self::SIGNAL_WEIGHTS['network_declined'];
        }

        return $this->clampScore($score);
    }

    /**
     * Extract Stripe Radar metadata from known order metadata locations.
     *
     * @return array<string, mixed>
     */
    protected function getStripeRadarMetadata(Order $order): array
    {
        $metadata = $order->metadata ?? [];
        $radar = data_get($metadata, 'stripe_radar')
            ?? data_get($metadata, 'stripe.outcome')
            ?? data_get($metadata, 'payment.outcome')
            ?? data_get($metadata, 'fraud_assessment');

        return is_array($radar) ? $radar : [];
    }

    protected function clampScore(int $score): int
    {
        return max(0, min(100, $score));
    }

    protected function recommendationForScore(int $score): string
    {
        $blockThreshold = (int) config('commerce.fraud.score.block_threshold', 80);
        $reviewThreshold = (int) config('commerce.fraud.score.review_threshold', 50);

        if ($score >= $blockThreshold) {
            return self::RECOMMENDATION_BLOCK;
        }

        if ($score >= $reviewThreshold) {
            return self::RECOMMENDATION_REVIEW;
        }

        return self::RECOMMENDATION_APPROVE;
    }

    protected function getBillingCountry(Order $order): ?string
    {
        return $this->normaliseCountry(
            data_get($order->billing_address, 'country')
            ?? data_get($order->metadata, 'billing_country')
            ?? $order->tax_country
        );
    }

    protected function normaliseCountry(mixed $country): ?string
    {
        if (! is_string($country) || trim($country) === '') {
            return null;
        }

        return strtoupper(substr(trim($country), 0, 2));
    }

    protected function getOrderIp(Order $order): ?string
    {
        $metadata = $order->metadata ?? [];
        $ip = data_get($metadata, 'ip_address')
            ?? data_get($metadata, 'ip')
            ?? data_get($metadata, 'customer_ip')
            ?? request()->ip();

        return is_string($ip) && trim($ip) !== '' ? trim($ip) : null;
    }

    protected function getOrderWorkspaceId(Order $order): ?int
    {
        $workspaceId = $order->getAttribute('workspace_id')
            ?? $order->getAttribute('workspaceId')
            ?? $order->workspace_id
            ?? $order->orderable_id;

        return $workspaceId === null ? null : (int) $workspaceId;
    }

    protected function normaliseReason(string $reason): string
    {
        $reason = trim((string) preg_replace('/[[:cntrl:]]+/', ' ', $reason));

        if ($reason === '') {
            throw new InvalidArgumentException('Fraud reason is required.');
        }

        return substr($reason, 0, self::MAX_REASON_LENGTH);
    }

    /**
     * @param  array<string, mixed>  $fraudState
     * @return array<string, mixed>
     */
    protected function metadataWithFraudState(Order $order, array $fraudState): array
    {
        $metadata = $order->metadata ?? [];
        $fraud = is_array($metadata['fraud'] ?? null) ? $metadata['fraud'] : [];
        $metadata['fraud'] = array_merge($fraud, $fraudState);

        return $metadata;
    }

    protected function previousOrderStatus(Order $order): string
    {
        if ($order->status !== self::ORDER_STATUS_PENDING_REVIEW) {
            return $order->status;
        }

        return data_get($order->metadata, 'fraud.previous_status', 'pending');
    }

    /**
     * Determine if order should be blocked based on risk level.
     */
    protected function shouldBlockOrder(string $riskLevel): bool
    {
        if (! config('commerce.fraud.actions.auto_block', true)) {
            return false;
        }

        $blockThreshold = config('commerce.fraud.stripe_radar.block_threshold', self::RISK_HIGHEST);

        return $this->riskLevelMeetsThreshold($riskLevel, $blockThreshold);
    }

    /**
     * Determine if order should be flagged for review.
     */
    protected function shouldReviewOrder(string $riskLevel): bool
    {
        $reviewThreshold = config('commerce.fraud.stripe_radar.review_threshold', self::RISK_ELEVATED);

        return $this->riskLevelMeetsThreshold($riskLevel, $reviewThreshold);
    }

    /**
     * Determine if payment should be blocked based on Stripe Radar risk level.
     */
    protected function shouldBlockPayment(string $riskLevel): bool
    {
        if (! config('commerce.fraud.actions.auto_block', true)) {
            return false;
        }

        $blockThreshold = config('commerce.fraud.stripe_radar.block_threshold', self::RISK_HIGHEST);

        return $this->riskLevelMeetsThreshold($riskLevel, $blockThreshold);
    }

    /**
     * Determine if payment should be flagged for review.
     */
    protected function shouldReviewPayment(string $riskLevel): bool
    {
        $reviewThreshold = config('commerce.fraud.stripe_radar.review_threshold', self::RISK_ELEVATED);

        return $this->riskLevelMeetsThreshold($riskLevel, $reviewThreshold);
    }

    /**
     * Check if a risk level meets or exceeds a threshold.
     */
    protected function riskLevelMeetsThreshold(string $riskLevel, string $threshold): bool
    {
        $levels = [
            self::RISK_NOT_ASSESSED => 0,
            self::RISK_NORMAL => 1,
            self::RISK_ELEVATED => 2,
            self::RISK_HIGHEST => 3,
        ];

        return ($levels[$riskLevel] ?? 0) >= ($levels[$threshold] ?? 0);
    }

    /**
     * Store fraud assessment on payment record.
     */
    protected function storeFraudAssessment(Payment $payment, FraudAssessment $assessment): void
    {
        $metadata = $payment->metadata ?? [];
        $metadata['fraud_assessment'] = [
            'risk_level' => $assessment->riskLevel,
            'risk_score' => $assessment->stripeRiskScore,
            'source' => $assessment->source,
            'signals' => $assessment->signals,
            'should_block' => $assessment->shouldBlock,
            'should_review' => $assessment->shouldReview,
            'assessed_at' => now()->toIso8601String(),
        ];

        $payment->update(['metadata' => $metadata]);
    }

    /**
     * Record a failed payment for velocity tracking.
     */
    public function recordFailedPayment(Order $order): void
    {
        $workspaceId = $this->getOrderWorkspaceId($order);

        if ($workspaceId) {
            $failedKey = "fraud:failed:workspace:{$workspaceId}";
            $failedCount = (int) Cache::get($failedKey, 0);
            Cache::put($failedKey, $failedCount + 1, now()->addHour());
        }
    }

    /**
     * Log fraud assessment.
     */
    protected function logAssessment(Order $order, FraudAssessment $assessment): void
    {
        if (! config('commerce.fraud.actions.log', true)) {
            return;
        }

        if ($assessment->riskLevel === self::RISK_NORMAL && empty($assessment->signals)) {
            return; // Don't log normal orders with no signals
        }

        Log::channel('fraud')->info('Order fraud assessment', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'risk_level' => $assessment->riskLevel,
            'signals' => $assessment->signals,
            'should_block' => $assessment->shouldBlock,
            'should_review' => $assessment->shouldReview,
        ]);
    }

    /**
     * Log payment fraud assessment.
     */
    protected function logPaymentAssessment(Payment $payment, FraudAssessment $assessment): void
    {
        if (! config('commerce.fraud.actions.log', true)) {
            return;
        }

        Log::channel('fraud')->info('Payment fraud assessment (Stripe Radar)', [
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'risk_level' => $assessment->riskLevel,
            'risk_score' => $assessment->stripeRiskScore,
            'signals' => $assessment->signals,
            'should_block' => $assessment->shouldBlock,
            'should_review' => $assessment->shouldReview,
        ]);

        // Notify admin if high risk and notifications enabled
        if ($assessment->shouldReview && config('commerce.fraud.actions.notify_admin', true)) {
            // This could dispatch a notification job
            // For now, just log at warning level
            Log::channel('fraud')->warning('High-risk payment requires review', [
                'payment_id' => $payment->id,
                'risk_level' => $assessment->riskLevel,
            ]);
        }
    }
}
