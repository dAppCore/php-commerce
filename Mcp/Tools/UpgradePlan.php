<?php

namespace Core\Mod\Commerce\Mcp\Tools;

use Core\Mod\Commerce\Models\Subscription;
use Core\Mod\Commerce\Services\SubscriptionService;
use Core\Tenant\Models\Package;
use Core\Tenant\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UpgradePlan extends Tool
{
    protected string $description = 'Preview or execute a plan upgrade/downgrade for a workspace subscription';

    public function handle(Request $request): Response
    {
        $workspaceId = $request->input('workspace_id');
        $newPackageCode = $request->input('package_code');
        $preview = $request->input('preview', true);
        $immediate = $request->input('immediate', true);

        $workspace = Workspace::find($workspaceId);

        if (! $workspace) {
            return Response::text(json_encode(['error' => 'Workspace not found']));
        }

        $newPackage = Package::where('code', $newPackageCode)->first();

        if (! $newPackage) {
            return Response::text(json_encode([
                'error' => 'Package not found',
                'available_packages' => Package::where('is_active', true)
                    ->where('is_public', true)
                    ->pluck('code')
                    ->all(),
            ]));
        }

        // Get active subscription
        $subscription = Subscription::with('workspacePackage.package')
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', ['active', 'trialing'])
            ->first();

        if (! $subscription) {
            return Response::text(json_encode([
                'error' => 'No active subscription found for this workspace',
            ]));
        }

        $subscriptionService = app(SubscriptionService::class);

        try {
            if ($preview) {
                // Preview the proration
                $proration = $subscriptionService->previewPlanChange($subscription, $newPackage);

                return Response::text(json_encode([
                    'preview' => true,
                    'current_package' => $subscription->workspacePackage?->package?->code,
                    'new_package' => $newPackage->code,
                    'proration' => [
                        'is_upgrade' => $proration->isUpgrade(),
                        'is_downgrade' => $proration->isDowngrade(),
                        'current_plan_price' => $proration->currentPlanPrice,
                        'new_plan_price' => $proration->newPlanPrice,
                        'credit_amount' => $proration->creditAmount,
                        'prorated_new_cost' => $proration->proratedNewPlanCost,
                        'net_amount' => $proration->netAmount,
                        'requires_payment' => $proration->requiresPayment(),
                        'days_remaining' => $proration->daysRemaining,
                        'currency' => $proration->currency,
                    ],
                ], JSON_PRETTY_PRINT));
            }

            // Execute the plan change
            $result = $subscriptionService->changePlan(
                $subscription,
                $newPackage,
                prorate: true,
                immediate: $immediate
            );

            return Response::text(json_encode([
                'success' => true,
                'immediate' => $result['immediate'],
                'current_package' => $subscription->workspacePackage?->package?->code,
                'new_package' => $newPackage->code,
                'proration' => $result['proration']?->toArray(),
                'subscription_status' => $result['subscription']->status,
            ], JSON_PRETTY_PRINT));

        } catch (\Exception $e) {
            return Response::text(json_encode([
                'error' => $e->getMessage(),
            ]));
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->integer('The workspace ID to upgrade/downgrade')->required(),
            'package_code' => $schema->string('The code of the new package (e.g., agency, enterprise)')->required(),
            'preview' => $schema->boolean('If true, only preview the change without executing (default: true)'),
            'immediate' => $schema->boolean('If true, apply change immediately; if false, schedule for period end (default: true)'),
        ];
    }
}
