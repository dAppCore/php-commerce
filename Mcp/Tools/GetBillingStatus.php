<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Mcp\Tools;

use Core\Mod\Commerce\Models\Subscription;
use Core\Tenant\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetBillingStatus extends Tool
{
    protected string $description = 'Get billing status for a workspace including subscription, current plan, and billing period';

    public function handle(Request $request): Response
    {
        $workspaceId = $request->input('workspace_id');

        $workspace = Workspace::find($workspaceId);

        if (! $workspace) {
            return Response::text(json_encode(['error' => 'Workspace not found']));
        }

        // Get active subscription
        $subscription = Subscription::with('workspacePackage.package')
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->first();

        // Get workspace packages
        $packages = $workspace->workspacePackages()
            ->with('package')
            ->whereIn('status', ['active', 'trial'])
            ->get();

        $status = [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
            ],
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'gateway' => $subscription->gateway,
                'billing_cycle' => $subscription->billing_cycle,
                'current_period_start' => $subscription->current_period_start?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'days_until_renewal' => $subscription->daysUntilRenewal(),
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'on_trial' => $subscription->onTrial(),
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            ] : null,
            'packages' => $packages->map(fn ($wp) => [
                'code' => $wp->package?->code,
                'name' => $wp->package?->name,
                'status' => $wp->status,
                'expires_at' => $wp->expires_at?->toIso8601String(),
            ])->values()->all(),
        ];

        return Response::text(json_encode($status, JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->integer('The workspace ID to get billing status for')->required(),
        ];
    }
}
