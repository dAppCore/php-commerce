<?php

use Carbon\Carbon;
use Core\Mod\Commerce\Exceptions\PauseLimitExceededException;
use Core\Mod\Commerce\Models\Subscription;
use Core\Mod\Commerce\Services\ProrationResult;
use Core\Mod\Commerce\Services\SubscriptionService;
use Core\Tenant\Models\Feature;
use Core\Tenant\Models\Package;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Core\Tenant\Models\WorkspacePackage;
use Illuminate\Support\Facades\Cache;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
    $this->workspace->users()->attach($this->user->id, [
        'role' => 'owner',
        'is_default' => true,
    ]);

    // Use existing seeded feature (or create test-specific one)
    $this->aiCreditsFeature = Feature::firstOrCreate(
        ['code' => 'ai.credits'],
        [
            'name' => 'AI Credits',
            'category' => 'ai',
            'type' => Feature::TYPE_LIMIT,
            'reset_type' => Feature::RESET_MONTHLY,
            'is_active' => true,
        ]
    );

    // Use existing seeded packages
    $this->creatorPackage = Package::where('code', 'creator')->firstOrFail();
    $this->agencyPackage = Package::where('code', 'agency')->firstOrFail();
    $this->enterprisePackage = Package::where('code', 'enterprise')->firstOrFail();

    // Ensure packages have expected prices for this test
    $this->creatorPackage->update(['monthly_price' => 19.00, 'yearly_price' => 190.00]);
    $this->agencyPackage->update(['monthly_price' => 49.00, 'yearly_price' => 490.00]);
    $this->enterprisePackage->update(['monthly_price' => 99.00, 'yearly_price' => 990.00]);

    // Attach features if not already attached
    if (! $this->creatorPackage->features()->where('feature_id', $this->aiCreditsFeature->id)->exists()) {
        $this->creatorPackage->features()->attach($this->aiCreditsFeature->id, ['limit_value' => 100]);
    }
    if (! $this->agencyPackage->features()->where('feature_id', $this->aiCreditsFeature->id)->exists()) {
        $this->agencyPackage->features()->attach($this->aiCreditsFeature->id, ['limit_value' => 500]);
    }
    if (! $this->enterprisePackage->features()->where('feature_id', $this->aiCreditsFeature->id)->exists()) {
        $this->enterprisePackage->features()->attach($this->aiCreditsFeature->id, ['limit_value' => null]); // Unlimited
    }

    $this->service = app(SubscriptionService::class);
});

describe('SubscriptionService', function () {
    describe('create() method', function () {
        it('creates a monthly subscription', function () {
            $workspacePackage = WorkspacePackage::create([
                'workspace_id' => $this->workspace->id,
                'package_id' => $this->creatorPackage->id,
                'status' => 'active',
            ]);

            $subscription = $this->service->create($workspacePackage, 'monthly');

            expect($subscription)->toBeInstanceOf(Subscription::class)
                ->and($subscription->workspace_id)->toBe($this->workspace->id)
                ->and($subscription->workspace_package_id)->toBe($workspacePackage->id)
                ->and($subscription->status)->toBe('active')
                ->and($subscription->billing_cycle)->toBe('monthly')
                ->and((int) $subscription->current_period_start->diffInDays($subscription->current_period_end))->toBe(30);
        });

        it('creates a yearly subscription', function () {
            $workspacePackage = WorkspacePackage::create([
                'workspace_id' => $this->workspace->id,
                'package_id' => $this->creatorPackage->id,
                'status' => 'active',
            ]);

            $subscription = $this->service->create($workspacePackage, 'yearly');

            expect($subscription->billing_cycle)->toBe('yearly')
                ->and((int) $subscription->current_period_start->diffInDays($subscription->current_period_end))->toBe(365);
        });
    });

    describe('cancel() method', function () {
        it('cancels a subscription', function () {
            $workspacePackage = WorkspacePackage::create([
                'workspace_id' => $this->workspace->id,
                'package_id' => $this->creatorPackage->id,
                'status' => 'active',
            ]);

            $subscription = $this->service->create($workspacePackage);
            $subscription = $this->service->cancel($subscription, 'Too expensive');

            expect($subscription->cancelled_at)->not->toBeNull()
                ->and($subscription->cancellation_reason)->toBe('Too expensive');
        });
    });

    describe('resume() method', function () {
        it('resumes a cancelled subscription within billing period', function () {
            $workspacePackage = WorkspacePackage::create([
                'workspace_id' => $this->workspace->id,
                'package_id' => $this->creatorPackage->id,
                'status' => 'active',
            ]);

            $subscription = $this->service->create($workspacePackage);
            $this->service->cancel($subscription, 'Changed mind');
            $subscription = $this->service->resume($subscription);

            expect($subscription->cancelled_at)->toBeNull()
                ->and($subscription->cancellation_reason)->toBeNull();
        });

        it('does not resume if period has ended', function () {
            $workspacePackage = WorkspacePackage::create([
                'workspace_id' => $this->workspace->id,
                'package_id' => $this->creatorPackage->id,
                'status' => 'active',
            ]);

            $subscription = $this->service->create($workspacePackage);
            $subscription->update(['current_period_end' => now()->subDay()]);
            $this->service->cancel($subscription);
            $subscription = $this->service->resume($subscription);

            // Should still be cancelled
            expect($subscription->cancelled_at)->not->toBeNull();
        });
    });

    describe('renew() method', function () {
        it('renews a subscription for another period', function () {
            $workspacePackage = WorkspacePackage::create([
                'workspace_id' => $this->workspace->id,
                'package_id' => $this->creatorPackage->id,
                'status' => 'active',
            ]);

            $subscription = $this->service->create($workspacePackage);
            $originalEnd = $subscription->current_period_end;

            // Move time forward
            Carbon::setTestNow($originalEnd);
            $subscription = $this->service->renew($subscription);

            expect($subscription->current_period_start->toDateString())->toBe($originalEnd->toDateString())
                ->and((int) $subscription->current_period_start->diffInDays($subscription->current_period_end))->toBe(30);

            Carbon::setTestNow(); // Reset
        });

        it('clears cancellation when renewing', function () {
            $workspacePackage = WorkspacePackage::create([
                'workspace_id' => $this->workspace->id,
                'package_id' => $this->creatorPackage->id,
                'status' => 'active',
            ]);

            $subscription = $this->service->create($workspacePackage);
            $subscription->update([
                'cancelled_at' => now(),
                'cancellation_reason' => 'Test',
            ]);

            $subscription = $this->service->renew($subscription);

            expect($subscription->cancelled_at)->toBeNull()
                ->and($subscription->cancellation_reason)->toBeNull();
        });
    });

    describe('expire() method', function () {
        it('expires a subscription immediately', function () {
            $workspacePackage = WorkspacePackage::create([
                'workspace_id' => $this->workspace->id,
                'package_id' => $this->creatorPackage->id,
                'status' => 'active',
            ]);

            $subscription = $this->service->create($workspacePackage);
            $subscription = $this->service->expire($subscription);

            expect($subscription->status)->toBe('expired')
                ->and($subscription->ended_at)->not->toBeNull();

            $workspacePackage->refresh();
            expect($workspacePackage->status)->toBe('expired');
        });
    });

    describe('pause() and unpause() methods', function () {
        it('pauses a subscription', function () {
            $workspacePackage = WorkspacePackage::create([
                'workspace_id' => $this->workspace->id,
                'package_id' => $this->creatorPackage->id,
                'status' => 'active',
            ]);

            $subscription = $this->service->create($workspacePackage);
            $subscription = $this->service->pause($subscription);

            expect($subscription->status)->toBe('paused')
                ->and($subscription->paused_at)->not->toBeNull()
                ->and($subscription->pause_count)->toBe(1);
        });

        it('increments pause count on each pause', function () {
            $workspacePackage = WorkspacePackage::create([
                'workspace_id' => $this->workspace->id,
                'package_id' => $this->creatorPackage->id,
                'status' => 'active',
            ]);

            $subscription = $this->service->create($workspacePackage);

            // First pause
            $subscription = $this->service->pause($subscription);
            expect($subscription->pause_count)->toBe(1);

            // Unpause
            $subscription = $this->service->unpause($subscription);

            // Second pause
            $subscription = $this->service->pause($subscription);
            expect($subscription->pause_count)->toBe(2);
        });

        it('throws exception when pause limit is exceeded', function () {
            config(['commerce.subscriptions.max_pause_cycles' => 2]);

            $workspacePackage = WorkspacePackage::create([
                'workspace_id' => $this->workspace->id,
                'package_id' => $this->creatorPackage->id,
                'status' => 'active',
            ]);

            $subscription = $this->service->create($workspacePackage);

            // First pause
            $subscription = $this->service->pause($subscription);
            $subscription = $this->service->unpause($subscription);

            // Second pause (at limit)
            $subscription = $this->service->pause($subscription);
            $subscription = $this->service->unpause($subscription);

            // Third pause should throw
            expect(fn () => $this->service->pause($subscription))
                ->toThrow(PauseLimitExceededException::class);
        });

        it('allows forced pause even when limit exceeded', function () {
            config(['commerce.subscriptions.max_pause_cycles' => 1]);

            $workspacePackage = WorkspacePackage::create([
                'workspace_id' => $this->workspace->id,
                'package_id' => $this->creatorPackage->id,
                'status' => 'active',
            ]);

            $subscription = $this->service->create($workspacePackage);

            // First pause (uses the limit)
            $subscription = $this->service->pause($subscription);
            $subscription = $this->service->unpause($subscription);

            // Force pause should work even when limit exceeded
            $subscription = $this->service->pause($subscription, force: true);

            expect($subscription->status)->toBe('paused')
                ->and($subscription->pause_count)->toBe(2);
        });

        it('reports canPause correctly', function () {
            config(['commerce.subscriptions.max_pause_cycles' => 2]);

            $workspacePackage = WorkspacePackage::create([
                'workspace_id' => $this->workspace->id,
                'package_id' => $this->creatorPackage->id,
                'status' => 'active',
            ]);

            $subscription = $this->service->create($workspacePackage);

            expect($subscription->canPause())->toBeTrue()
                ->and($subscription->remainingPauseCycles())->toBe(2);

            // First pause
            $subscription = $this->service->pause($subscription);
            $subscription = $this->service->unpause($subscription);

            expect($subscription->canPause())->toBeTrue()
                ->and($subscription->remainingPauseCycles())->toBe(1);

            // Second pause
            $subscription = $this->service->pause($subscription);
            $subscription = $this->service->unpause($subscription);

            expect($subscription->canPause())->toBeFalse()
                ->and($subscription->remainingPauseCycles())->toBe(0);
        });

        it('unpauses a subscription', function () {
            $workspacePackage = WorkspacePackage::create([
                'workspace_id' => $this->workspace->id,
                'package_id' => $this->creatorPackage->id,
                'status' => 'active',
            ]);

            $subscription = $this->service->create($workspacePackage);
            $this->service->pause($subscription);
            $subscription = $this->service->unpause($subscription);

            expect($subscription->status)->toBe('active')
                ->and($subscription->paused_at)->toBeNull();
        });
    });
});

describe('Proration calculations', function () {
    beforeEach(function () {
        // Freeze time for predictable day calculations
        Carbon::setTestNow(Carbon::now()->startOfDay()->addHours(12));

        $workspacePackage = WorkspacePackage::create([
            'workspace_id' => $this->workspace->id,
            'package_id' => $this->creatorPackage->id,
            'status' => 'active',
        ]);

        $this->subscription = Subscription::create([
            'workspace_id' => $this->workspace->id,
            'workspace_package_id' => $workspacePackage->id,
            'status' => 'active',
            'gateway' => 'btcpay',
            'billing_cycle' => 'monthly',
            'current_period_start' => now()->subDays(15),
            'current_period_end' => now()->addDays(15),
        ]);
    });

    afterEach(function () {
        Carbon::setTestNow(); // Reset frozen time
    });

    describe('calculateProration() method', function () {
        it('calculates proration for upgrade mid-cycle', function () {
            $proration = $this->service->calculateProration(
                $this->subscription,
                $this->creatorPackage,  // £19/month
                $this->agencyPackage,   // £49/month
                'monthly'
            );

            expect($proration)->toBeInstanceOf(ProrationResult::class)
                ->and($proration->currentPlanPrice)->toBe(19.00)
                ->and($proration->newPlanPrice)->toBe(49.00)
                ->and($proration->isUpgrade())->toBeTrue()
                ->and($proration->requiresPayment())->toBeTrue();
        });

        it('calculates proration for downgrade mid-cycle', function () {
            // Start with agency package
            $this->subscription->workspacePackage->update(['package_id' => $this->agencyPackage->id]);

            $proration = $this->service->calculateProration(
                $this->subscription,
                $this->agencyPackage,   // £49/month
                $this->creatorPackage,  // £19/month
                'monthly'
            );

            expect($proration->isDowngrade())->toBeTrue()
                ->and($proration->netAmount)->toBeLessThan(0)
                ->and($proration->getCreditBalance())->toBeGreaterThan(0);
        });

        it('calculates days remaining correctly', function () {
            $proration = $this->service->calculateProration(
                $this->subscription,
                $this->creatorPackage,
                $this->agencyPackage,
                'monthly'
            );

            expect($proration->daysRemaining)->toBe(15)
                ->and($proration->totalPeriodDays)->toBe(30);
        });

        it('calculates credit amount based on unused time', function () {
            $proration = $this->service->calculateProration(
                $this->subscription,
                $this->creatorPackage,
                $this->agencyPackage,
                'monthly'
            );

            // 15 days remaining out of 30 = 50% unused
            // Credit = £19 * 0.5 = £9.50
            expect($proration->creditAmount)->toBe(9.50)
                ->and($proration->usedPercentage)->toBe(0.5);
        });

        it('calculates prorated new plan cost', function () {
            $proration = $this->service->calculateProration(
                $this->subscription,
                $this->creatorPackage,
                $this->agencyPackage,
                'monthly'
            );

            // 15 days remaining out of 30 = 50%
            // Prorated new plan = £49 * 0.5 = £24.50
            expect($proration->proratedNewPlanCost)->toBe(24.50);
        });

        it('calculates net amount correctly', function () {
            $proration = $this->service->calculateProration(
                $this->subscription,
                $this->creatorPackage,
                $this->agencyPackage,
                'monthly'
            );

            // Net = Prorated new - Credit = £24.50 - £9.50 = £15.00
            expect($proration->netAmount)->toBe(15.00)
                ->and($proration->getAmountDue())->toBe(15.00);
        });
    });

    describe('previewPlanChange() method', function () {
        it('returns proration preview without making changes', function () {
            $proration = $this->service->previewPlanChange(
                $this->subscription,
                $this->agencyPackage
            );

            expect($proration)->toBeInstanceOf(ProrationResult::class)
                ->and($proration->isUpgrade())->toBeTrue();

            // Verify no changes were made
            $this->subscription->refresh();
            expect($this->subscription->workspacePackage->package->code)->toBe('creator');
        });

        it('throws exception if subscription has no current package', function () {
            // Mock the workspacePackage to have null package
            $this->subscription->workspacePackage->setRelation('package', null);

            expect(fn () => $this->service->previewPlanChange($this->subscription, $this->agencyPackage))
                ->toThrow(\InvalidArgumentException::class, 'no current package');
        });
    });

    describe('scheduled plan changes', function () {
        it('schedules plan change for period end', function () {
            $result = $this->service->changePlan(
                $this->subscription,
                $this->agencyPackage,
                prorate: false,
                immediate: false
            );

            expect($result['immediate'])->toBeFalse()
                ->and($this->service->hasPendingPlanChange($result['subscription']))->toBeTrue();

            $pending = $this->service->getPendingPlanChange($result['subscription']);
            expect($pending['to_package_code'])->toBe('agency');
        });

        it('cancels scheduled plan change', function () {
            $result = $this->service->changePlan(
                $this->subscription,
                $this->agencyPackage,
                immediate: false
            );

            $subscription = $this->service->cancelScheduledPlanChange($result['subscription']);

            expect($this->service->hasPendingPlanChange($subscription))->toBeFalse();
        });
    });
});

describe('ProrationResult', function () {
    it('converts to array correctly', function () {
        $result = new ProrationResult(
            daysRemaining: 15,
            totalPeriodDays: 30,
            usedPercentage: 0.5,
            currentPlanPrice: 19.00,
            newPlanPrice: 49.00,
            creditAmount: 9.50,
            proratedNewPlanCost: 24.50,
            netAmount: 15.00,
            currency: 'GBP'
        );

        $array = $result->toArray();

        expect($array)->toHaveKeys([
            'days_remaining',
            'total_period_days',
            'used_percentage',
            'current_plan_price',
            'new_plan_price',
            'credit_amount',
            'prorated_new_plan_cost',
            'net_amount',
            'currency',
            'is_upgrade',
            'is_downgrade',
            'requires_payment',
        ])
            ->and($array['is_upgrade'])->toBeTrue()
            ->and($array['is_downgrade'])->toBeFalse()
            ->and($array['requires_payment'])->toBeTrue();
    });

    it('identifies same price plans', function () {
        $result = new ProrationResult(
            daysRemaining: 15,
            totalPeriodDays: 30,
            usedPercentage: 0.5,
            currentPlanPrice: 49.00,
            newPlanPrice: 49.00,
            creditAmount: 24.50,
            proratedNewPlanCost: 24.50,
            netAmount: 0.00,
        );

        expect($result->isSamePrice())->toBeTrue()
            ->and($result->isUpgrade())->toBeFalse()
            ->and($result->isDowngrade())->toBeFalse()
            ->and($result->requiresPayment())->toBeFalse();
    });
});
