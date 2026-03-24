<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Core\Mod\Commerce\Models\CreditNote;
use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\Refund;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreditNoteService
{
    /**
     * Create a new credit note.
     */
    public function create(
        Workspace $workspace,
        User $user,
        float $amount,
        string $reason,
        ?string $description = null,
        string $currency = 'GBP',
        ?User $issuedBy = null,
        bool $issueImmediately = true
    ): CreditNote {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit note amount must be greater than zero');
        }

        return DB::transaction(function () use ($workspace, $user, $amount, $reason, $description, $currency, $issuedBy, $issueImmediately) {
            $creditNote = CreditNote::create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'reference_number' => CreditNote::generateReferenceNumber(),
                'amount' => $amount,
                'currency' => $currency,
                'reason' => $reason,
                'description' => $description,
                'status' => 'draft',
            ]);

            if ($issueImmediately) {
                $creditNote->issue($issuedBy);
            }

            Log::info('Credit note created', [
                'credit_note_id' => $creditNote->id,
                'reference' => $creditNote->reference_number,
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'reason' => $reason,
            ]);

            return $creditNote;
        });
    }

    /**
     * Create a credit note from a refund (partial refund as store credit).
     */
    public function createFromRefund(
        Refund $refund,
        float $amount,
        ?string $description = null,
        ?User $issuedBy = null
    ): CreditNote {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit note amount must be greater than zero');
        }

        if ($amount > $refund->amount) {
            throw new \InvalidArgumentException('Credit note amount cannot exceed refund amount');
        }

        $payment = $refund->payment;
        $workspace = $payment->workspace;

        // Get user from the payment's workspace owner
        $user = $workspace->owner();

        if (! $user) {
            throw new \InvalidArgumentException('Cannot create credit note: no workspace owner found');
        }

        return DB::transaction(function () use ($workspace, $user, $refund, $amount, $description, $issuedBy, $payment) {
            // Get order from payment if available
            $orderId = $payment->invoice?->order_id;

            $creditNote = CreditNote::create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'order_id' => $orderId,
                'refund_id' => $refund->id,
                'reference_number' => CreditNote::generateReferenceNumber(),
                'amount' => $amount,
                'currency' => $refund->currency,
                'reason' => 'partial_refund',
                'description' => $description ?? "Credit from refund #{$refund->id}",
                'status' => 'draft',
            ]);

            $creditNote->issue($issuedBy);

            Log::info('Credit note created from refund', [
                'credit_note_id' => $creditNote->id,
                'refund_id' => $refund->id,
                'amount' => $amount,
            ]);

            return $creditNote;
        });
    }

    /**
     * Apply a credit note to an order.
     *
     * @return float Amount applied
     */
    public function apply(CreditNote $creditNote, Order $order, ?float $amount = null): float
    {
        if (! $creditNote->isUsable()) {
            throw new \InvalidArgumentException('Credit note is not usable (status: '.$creditNote->status.')');
        }

        $available = $creditNote->getRemainingAmount();

        if ($available <= 0) {
            throw new \InvalidArgumentException('Credit note has no remaining balance');
        }

        // If no amount specified, use the full remaining amount
        $applyAmount = $amount ?? $available;

        // Cap at available amount
        if ($applyAmount > $available) {
            $applyAmount = $available;
        }

        // Cap at order total
        if ($applyAmount > $order->total) {
            $applyAmount = $order->total;
        }

        return DB::transaction(function () use ($creditNote, $order, $applyAmount) {
            $creditNote->recordUsage($applyAmount, $order);

            // Update order metadata to track credit applied
            $order->update([
                'metadata' => array_merge($order->metadata ?? [], [
                    'credits_applied' => array_merge(
                        $order->metadata['credits_applied'] ?? [],
                        [[
                            'credit_note_id' => $creditNote->id,
                            'reference' => $creditNote->reference_number,
                            'amount' => $applyAmount,
                            'applied_at' => now()->toIso8601String(),
                        ]]
                    ),
                ]),
            ]);

            Log::info('Credit note applied to order', [
                'credit_note_id' => $creditNote->id,
                'order_id' => $order->id,
                'amount_applied' => $applyAmount,
            ]);

            return $applyAmount;
        });
    }

    /**
     * Void a credit note.
     */
    public function void(CreditNote $creditNote, ?User $voidedBy = null): void
    {
        if ($creditNote->isVoid()) {
            throw new \InvalidArgumentException('Credit note is already void');
        }

        if ($creditNote->amount_used > 0) {
            throw new \InvalidArgumentException('Cannot void a credit note that has been partially or fully used');
        }

        $creditNote->void($voidedBy);

        Log::info('Credit note voided', [
            'credit_note_id' => $creditNote->id,
            'reference' => $creditNote->reference_number,
            'voided_by' => $voidedBy?->id,
        ]);
    }

    /**
     * Get available (usable) credits for a user in a workspace.
     */
    public function getAvailableCredits(User $user, Workspace $workspace): Collection
    {
        return CreditNote::query()
            ->forWorkspace($workspace->id)
            ->forUser($user->id)
            ->usable()
            ->where('amount_used', '<', DB::raw('amount'))
            ->orderBy('created_at', 'asc') // FIFO - oldest credits first
            ->get();
    }

    /**
     * Get total available credit amount for a user in a workspace.
     */
    public function getTotalCredit(User $user, Workspace $workspace): float
    {
        return (float) CreditNote::query()
            ->forWorkspace($workspace->id)
            ->forUser($user->id)
            ->usable()
            ->selectRaw('SUM(amount - amount_used) as total')
            ->value('total') ?? 0;
    }

    /**
     * Get total available credit for a workspace (all users).
     */
    public function getTotalCreditForWorkspace(Workspace $workspace): float
    {
        return (float) CreditNote::query()
            ->forWorkspace($workspace->id)
            ->usable()
            ->selectRaw('SUM(amount - amount_used) as total')
            ->value('total') ?? 0;
    }

    /**
     * Get all credit notes for a workspace.
     */
    public function getCreditNotesForWorkspace(int $workspaceId): Collection
    {
        return CreditNote::query()
            ->forWorkspace($workspaceId)
            ->with(['user', 'order', 'refund', 'issuedByUser'])
            ->latest()
            ->get();
    }

    /**
     * Get all credit notes for a user.
     */
    public function getCreditNotesForUser(int $userId, ?int $workspaceId = null): Collection
    {
        return CreditNote::query()
            ->forUser($userId)
            ->when($workspaceId, fn ($q) => $q->forWorkspace($workspaceId))
            ->with(['workspace', 'order', 'refund'])
            ->latest()
            ->get();
    }

    /**
     * Auto-apply available credits to an order.
     *
     * @return float Total amount applied
     */
    public function autoApplyCredits(Order $order, User $user, Workspace $workspace): float
    {
        $availableCredits = $this->getAvailableCredits($user, $workspace);
        $totalApplied = 0;
        $remainingTotal = $order->total;

        foreach ($availableCredits as $creditNote) {
            if ($remainingTotal <= 0) {
                break;
            }

            $applyAmount = min($creditNote->getRemainingAmount(), $remainingTotal);
            $applied = $this->apply($creditNote, $order, $applyAmount);
            $totalApplied += $applied;
            $remainingTotal -= $applied;
        }

        return $totalApplied;
    }
}
