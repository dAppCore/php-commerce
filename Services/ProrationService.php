<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Carbon\Carbon;
use Core\Mod\Commerce\DTOs\ProrationResult;
use Core\Mod\Commerce\Models\CreditNote;
use Core\Mod\Commerce\Models\Invoice;
use Core\Mod\Commerce\Models\InvoiceItem;
use Core\Mod\Commerce\Models\Product;
use Core\Mod\Commerce\Models\Subscription;
use Illuminate\Support\Facades\DB;

class ProrationService
{
    public function calculate(Subscription $subscription, Product $newProduct, ?Carbon $effectiveDate = null): ProrationResult
    {
        $effectiveDate ??= now();
        $periodStart = $subscription->current_period_start ?? $effectiveDate->copy();
        $periodEnd = $subscription->current_period_end ?? $effectiveDate->copy();

        $totalSeconds = max(1, $periodStart->diffInSeconds($periodEnd, absolute: true));
        $remainingSeconds = max(0, $effectiveDate->diffInSeconds($periodEnd, absolute: false));
        $remainingRatio = min(1, $remainingSeconds / $totalSeconds);

        $oldProduct = $subscription->product;
        $oldPlanPrice = $oldProduct instanceof Product
            ? $this->periodPrice($oldProduct, $subscription->billing_cycle ?? 'monthly')
            : 0.0;
        $newPlanPrice = $this->periodPrice($newProduct, $subscription->billing_cycle ?? 'monthly');

        return new ProrationResult(
            creditAmount: round($oldPlanPrice * $remainingRatio, 2),
            chargeAmount: round($newPlanPrice * $remainingRatio, 2),
            effectiveDate: $effectiveDate,
        );
    }

    /**
     * Apply the proration by creating a credit note for unused old plan time and
     * an invoice for the new plan remainder.
     *
     * @return array{credit_note: CreditNote|null, invoice: Invoice|null}
     */
    public function apply(Subscription $subscription, Product $newProduct, ProrationResult $proration): array
    {
        return DB::transaction(function () use ($subscription, $newProduct, $proration): array {
            $creditNote = $proration->creditAmount > 0
                ? $this->createCreditNote($subscription, $proration)
                : null;

            $netCharge = max(0, $proration->chargeAmount - $proration->creditAmount);
            $invoice = $netCharge > 0
                ? $this->createInvoice($subscription, $newProduct, $netCharge, $proration)
                : null;

            $subscription->update([
                'product_id' => $newProduct->id,
                'metadata' => array_merge($subscription->metadata ?? [], [
                    'last_proration' => $proration->toArray(),
                ]),
            ]);

            return [
                'credit_note' => $creditNote,
                'invoice' => $invoice,
            ];
        });
    }

    protected function createCreditNote(Subscription $subscription, ProrationResult $proration): ?CreditNote
    {
        $workspace = $subscription->workspace;
        $user = $workspace?->owner();

        if (! $workspace || ! $user) {
            return null;
        }

        return CreditNote::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'invoice_id' => null,
            'reference_number' => CreditNote::generateReferenceNumber(),
            'amount' => $proration->creditAmount,
            'currency' => config('commerce.currency', 'GBP'),
            'reason' => 'subscription_proration',
            'description' => 'Credit for unused subscription time',
            'status' => 'issued',
            'issued_at' => now(),
            'metadata' => [
                'subscription_id' => $subscription->id,
                'proration' => $proration->toArray(),
            ],
        ]);
    }

    protected function createInvoice(
        Subscription $subscription,
        Product $newProduct,
        float $amount,
        ProrationResult $proration
    ): ?Invoice {
        $workspace = $subscription->workspace;

        if (! $workspace) {
            return null;
        }

        $invoice = Invoice::create([
            'workspace_id' => $workspace->id,
            'invoice_number' => Invoice::generateInvoiceNumber(),
            'status' => 'pending',
            'subtotal' => $amount,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total' => $amount,
            'amount_paid' => 0,
            'amount_due' => $amount,
            'currency' => config('commerce.currency', 'GBP'),
            'billing_name' => $workspace->billing_name ?? $workspace->name,
            'billing_email' => $workspace->billing_email ?? $workspace->owner()?->email,
            'billing_address' => method_exists($workspace, 'getBillingAddress') ? $workspace->getBillingAddress() : null,
            'issue_date' => now(),
            'due_date' => now()->addDays(config('commerce.billing.invoice_due_days', 14)),
            'metadata' => [
                'subscription_id' => $subscription->id,
                'product_id' => $newProduct->id,
                'proration' => $proration->toArray(),
            ],
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => "Prorated subscription change to {$newProduct->name}",
            'quantity' => 1,
            'unit_price' => $amount,
            'line_total' => $amount,
            'taxable' => true,
            'tax_rate' => 0,
            'tax_amount' => 0,
        ]);

        return $invoice;
    }

    protected function periodPrice(Product $product, string $billingCycle): float
    {
        $price = $product->prices()
            ->where('currency', config('commerce.currency', 'GBP'))
            ->where(function ($query) use ($billingCycle): void {
                $query->whereNull('billing_cycle')->orWhere('billing_cycle', $billingCycle);
            })
            ->orderByRaw('billing_cycle IS NULL')
            ->first();

        if ($price) {
            return $price->amount / 100;
        }

        if (isset($product->price)) {
            return ((int) $product->price) / 100;
        }

        return (float) ($product->base_price ?? 0);
    }
}
