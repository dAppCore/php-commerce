<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Controllers\Api;

use Core\Front\Controller;
use Core\Mod\Commerce\Models\Invoice;
use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\Subscription;
use Core\Mod\Commerce\Services\CommerceService;
use Core\Mod\Commerce\Services\InvoiceService;
use Core\Mod\Commerce\Services\SubscriptionService;
use Core\Tenant\Models\Package;
use Core\Tenant\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Commerce REST API for MCP agents and external integrations.
 *
 * Provides read access to orders, invoices, subscriptions, and usage,
 * plus plan upgrade/downgrade capabilities.
 */
class CommerceController extends Controller
{
    public function __construct(
        protected CommerceService $commerceService,
        protected SubscriptionService $subscriptionService,
        protected InvoiceService $invoiceService,
    ) {}

    /**
     * Get the current workspace from the authenticated user.
     */
    protected function getWorkspace(Request $request): ?Workspace
    {
        $user = Auth::user();

        if (! $user instanceof \Core\Tenant\Models\User) {
            return null;
        }

        // Allow workspace_id override for admin users
        if ($request->has('workspace_id') && $user->isAdmin()) {
            return Workspace::find($request->get('workspace_id'));
        }

        return $user->defaultHostWorkspace();
    }

    /**
     * List orders for the workspace.
     *
     * GET /api/v1/commerce/orders
     */
    public function orders(Request $request): JsonResponse
    {
        $workspace = $this->getWorkspace($request);

        if (! $workspace) {
            return response()->json(['error' => 'No workspace found'], 404);
        }

        $query = $workspace->orders()
            ->with(['items', 'invoice'])
            ->latest();

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $orders = $query->paginate($request->get('per_page', 25));

        return response()->json($orders);
    }

    /**
     * Get a specific order.
     *
     * GET /api/v1/commerce/orders/{order}
     */
    public function showOrder(Request $request, Order $order): JsonResponse
    {
        $workspace = $this->getWorkspace($request);

        if (! $workspace || $order->workspace_id !== $workspace->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $order->load(['items', 'payments', 'invoice']);

        return response()->json(['data' => $order]);
    }

    /**
     * List invoices for the workspace.
     *
     * GET /api/v1/commerce/invoices
     */
    public function invoices(Request $request): JsonResponse
    {
        $workspace = $this->getWorkspace($request);

        if (! $workspace) {
            return response()->json(['error' => 'No workspace found'], 404);
        }

        $query = $workspace->invoices()
            ->with(['items'])
            ->latest();

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $invoices = $query->paginate($request->get('per_page', 25));

        return response()->json($invoices);
    }

    /**
     * Get a specific invoice.
     *
     * GET /api/v1/commerce/invoices/{invoice}
     */
    public function showInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        $workspace = $this->getWorkspace($request);

        if (! $workspace || $invoice->workspace_id !== $workspace->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $invoice->load(['items', 'payment']);

        return response()->json(['data' => $invoice]);
    }

    /**
     * Download invoice PDF.
     *
     * GET /api/v1/commerce/invoices/{invoice}/download
     */
    public function downloadInvoice(Request $request, Invoice $invoice)
    {
        $workspace = $this->getWorkspace($request);

        if (! $workspace || $invoice->workspace_id !== $workspace->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return $this->invoiceService->downloadPdf($invoice);
    }

    /**
     * Get current subscription status.
     *
     * GET /api/v1/commerce/subscription
     */
    public function subscription(Request $request): JsonResponse
    {
        $workspace = $this->getWorkspace($request);

        if (! $workspace) {
            return response()->json(['error' => 'No workspace found'], 404);
        }

        $subscription = $workspace->subscriptions()
            ->with(['order.items'])
            ->active()
            ->latest()
            ->first();

        if (! $subscription) {
            return response()->json([
                'data' => null,
                'message' => 'No active subscription',
            ]);
        }

        return response()->json([
            'data' => $subscription,
            'next_billing_date' => $subscription->current_period_end?->toIso8601String(),
            'is_cancelled' => $subscription->cancel_at_period_end,
        ]);
    }

    /**
     * Get usage summary for the workspace.
     *
     * GET /api/v1/commerce/usage
     */
    public function usage(Request $request): JsonResponse
    {
        $workspace = $this->getWorkspace($request);

        if (! $workspace) {
            return response()->json(['error' => 'No workspace found'], 404);
        }

        $entitlements = app(\Core\Tenant\Services\EntitlementService::class);
        $summary = $entitlements->getUsageSummary($workspace);

        return response()->json([
            'data' => $summary,
            'workspace_id' => $workspace->id,
            'period' => now()->format('Y-m'),
        ]);
    }

    /**
     * Preview a plan change (upgrade/downgrade).
     *
     * POST /api/v1/commerce/upgrade/preview
     */
    public function previewUpgrade(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'package_code' => 'required|string|exists:entitlement_packages,code',
        ]);

        $workspace = $this->getWorkspace($request);

        if (! $workspace) {
            return response()->json(['error' => 'No workspace found'], 404);
        }

        $subscription = $workspace->subscriptions()
            ->with('workspacePackage.package')
            ->active()
            ->first();

        if (! $subscription) {
            return response()->json([
                'error' => 'No active subscription to upgrade',
            ], 400);
        }

        try {
            $newPackage = Package::where('code', $validated['package_code'])->firstOrFail();
            $currentPackage = $subscription->workspacePackage?->package;
            $billingCycle = $subscription->billing_cycle ?? 'monthly';

            $proration = $this->subscriptionService->previewPlanChange(
                $subscription,
                $newPackage,
                $billingCycle
            );

            return response()->json([
                'data' => [
                    'current_plan' => [
                        'name' => $currentPackage?->name ?? 'Current Plan',
                        'code' => $currentPackage?->code,
                        'price' => $proration->currentPlanPrice,
                    ],
                    'new_plan' => [
                        'name' => $newPackage->name,
                        'code' => $newPackage->code,
                        'price' => $proration->newPlanPrice,
                    ],
                    'billing_cycle' => $billingCycle,
                    'proration' => [
                        'days_remaining' => $proration->daysRemaining,
                        'total_period_days' => $proration->totalPeriodDays,
                        'used_percentage' => round($proration->usedPercentage * 100, 2),
                        'credit_amount' => $proration->creditAmount,
                        'prorated_new_cost' => $proration->proratedNewPlanCost,
                        'net_amount' => $proration->netAmount,
                    ],
                    'effective_date' => now()->toIso8601String(),
                    'next_billing_amount' => $proration->newPlanPrice,
                    'next_billing_date' => $subscription->current_period_end?->toIso8601String(),
                    'is_upgrade' => $proration->isUpgrade(),
                    'is_downgrade' => $proration->isDowngrade(),
                    'requires_payment' => $proration->requiresPayment(),
                    'currency' => $proration->currency,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Unable to preview plan change',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Execute a plan change (upgrade/downgrade).
     *
     * POST /api/v1/commerce/upgrade
     */
    public function executeUpgrade(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'package_code' => 'required|string|exists:entitlement_packages,code',
            'prorate' => 'boolean',
        ]);

        $workspace = $this->getWorkspace($request);

        if (! $workspace) {
            return response()->json(['error' => 'No workspace found'], 404);
        }

        $subscription = $workspace->subscriptions()->active()->first();

        if (! $subscription) {
            return response()->json([
                'error' => 'No active subscription to upgrade',
            ], 400);
        }

        try {
            $newPackage = Package::where('code', $validated['package_code'])->firstOrFail();

            $result = $this->subscriptionService->changePlan(
                $subscription,
                $newPackage,
                $validated['prorate'] ?? true
            );

            return response()->json([
                'data' => $result,
                'message' => 'Plan changed successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Unable to change plan',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Cancel the current subscription.
     *
     * POST /api/v1/commerce/cancel
     */
    public function cancelSubscription(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'immediately' => 'boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        $workspace = $this->getWorkspace($request);

        if (! $workspace) {
            return response()->json(['error' => 'No workspace found'], 404);
        }

        $subscription = $workspace->subscriptions()->active()->first();

        if (! $subscription) {
            return response()->json([
                'error' => 'No active subscription to cancel',
            ], 400);
        }

        try {
            $this->subscriptionService->cancel(
                $subscription,
                $validated['immediately'] ?? false,
                $validated['reason'] ?? null
            );

            return response()->json([
                'message' => $validated['immediately'] ?? false
                    ? 'Subscription cancelled immediately'
                    : 'Subscription will be cancelled at end of billing period',
                'ends_at' => $subscription->fresh()->current_period_end?->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Unable to cancel subscription',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Resume a cancelled subscription.
     *
     * POST /api/v1/commerce/resume
     */
    public function resumeSubscription(Request $request): JsonResponse
    {
        $workspace = $this->getWorkspace($request);

        if (! $workspace) {
            return response()->json(['error' => 'No workspace found'], 404);
        }

        $subscription = $workspace->subscriptions()
            ->where('cancel_at_period_end', true)
            ->where('status', 'active')
            ->first();

        if (! $subscription) {
            return response()->json([
                'error' => 'No cancelled subscription to resume',
            ], 400);
        }

        try {
            $this->subscriptionService->resume($subscription);

            return response()->json([
                'message' => 'Subscription resumed successfully',
                'data' => $subscription->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Unable to resume subscription',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get billing overview (summary of all billing data).
     *
     * GET /api/v1/commerce/billing
     */
    public function billing(Request $request): JsonResponse
    {
        $workspace = $this->getWorkspace($request);

        if (! $workspace) {
            return response()->json(['error' => 'No workspace found'], 404);
        }

        $subscription = $workspace->subscriptions()
            ->with(['order.items'])
            ->active()
            ->latest()
            ->first();

        $unpaidInvoices = $workspace->invoices()
            ->pending()
            ->sum('amount_due');

        $recentPayments = $workspace->payments()
            ->where('status', 'succeeded')
            ->latest()
            ->take(5)
            ->get();

        $defaultPaymentMethod = $workspace->paymentMethods()
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        return response()->json([
            'data' => [
                'subscription' => $subscription ? [
                    'id' => $subscription->id,
                    'status' => $subscription->status,
                    'plan_name' => $subscription->order?->items->first()?->name,
                    'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                    'cancel_at_period_end' => $subscription->cancel_at_period_end,
                ] : null,
                'outstanding_balance' => $unpaidInvoices,
                'currency' => config('commerce.currency', 'GBP'),
                'payment_method' => $defaultPaymentMethod ? [
                    'type' => $defaultPaymentMethod->type,
                    'brand' => $defaultPaymentMethod->brand,
                    'last_four' => $defaultPaymentMethod->last_four,
                    'exp_month' => $defaultPaymentMethod->exp_month,
                    'exp_year' => $defaultPaymentMethod->exp_year,
                ] : null,
                'recent_payments' => $recentPayments->map(fn ($p) => [
                    'amount' => $p->amount,
                    'currency' => $p->currency,
                    'status' => $p->status,
                    'created_at' => $p->created_at->toIso8601String(),
                ]),
            ],
        ]);
    }
}
