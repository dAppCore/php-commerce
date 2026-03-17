<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Core\Mod\Commerce\Mail\InvoiceGenerated;
use Core\Mod\Commerce\Models\Invoice;
use Core\Mod\Commerce\Models\InvoiceItem;
use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\Payment;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invoice generation and management service.
 */
class InvoiceService
{
    public function __construct(
        protected TaxService $taxService,
    ) {}

    /**
     * Create an invoice from an order.
     */
    public function createFromOrder(Order $order, ?Payment $payment = null): Invoice
    {
        $amountDue = $payment ? 0 : $order->total;

        // Resolve workspace ID from polymorphic orderable (Workspace or User)
        $workspaceId = $order->workspace_id;

        $invoice = Invoice::create([
            'workspace_id' => $workspaceId,
            'order_id' => $order->id,
            'payment_id' => $payment?->id,
            'invoice_number' => Invoice::generateInvoiceNumber(),
            'status' => $payment ? 'paid' : 'pending',
            'subtotal' => $order->subtotal,
            'discount_amount' => $order->discount_amount ?? 0,
            'tax_amount' => $order->tax_amount ?? 0,
            'tax_rate' => $order->tax_rate ?? 0,
            'tax_country' => $order->tax_country,
            'total' => $order->total,
            'amount_paid' => $payment ? $order->total : 0,
            'amount_due' => $amountDue,
            'currency' => $order->currency,
            'billing_name' => $order->billing_name,
            'billing_email' => $order->billing_email,
            'billing_address' => $order->billing_address,
            'issue_date' => now(),
            'due_date' => now()->addDays(config('commerce.billing.invoice_due_days', 14)),
            'paid_at' => $payment ? now() : null,
        ]);

        // Copy line items from order
        foreach ($order->items as $orderItem) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'order_item_id' => $orderItem->id,
                'description' => $orderItem->description,
                'quantity' => $orderItem->quantity,
                'unit_price' => $orderItem->unit_price,
                'line_total' => $orderItem->line_total,
                'tax_rate' => $order->tax_rate ?? 0,
                'tax_amount' => ($orderItem->line_total - $orderItem->unit_price * $orderItem->quantity),
            ]);
        }

        return $invoice;
    }

    /**
     * Create an invoice for a subscription renewal.
     */
    public function createForRenewal(
        Workspace $workspace,
        float $amount,
        string $description,
        ?Payment $payment = null
    ): Invoice {
        $taxResult = $this->taxService->calculate($workspace, $amount);

        $total = $amount + $taxResult->taxAmount;
        $amountDue = $payment ? 0 : $total;

        $invoice = Invoice::create([
            'workspace_id' => $workspace->id,
            'invoice_number' => Invoice::generateInvoiceNumber(),
            'status' => $payment ? 'paid' : 'pending',
            'subtotal' => $amount,
            'discount_amount' => 0,
            'tax_amount' => $taxResult->taxAmount,
            'tax_rate' => $taxResult->taxRate,
            'tax_country' => $taxResult->jurisdiction,
            'total' => $total,
            'amount_paid' => $payment ? $total : 0,
            'amount_due' => $amountDue,
            'currency' => config('commerce.currency', 'GBP'),
            'billing_name' => $workspace->billing_name,
            'billing_email' => $workspace->billing_email,
            'billing_address' => $workspace->getBillingAddress(),
            'issue_date' => now(),
            'due_date' => now()->addDays(config('commerce.billing.invoice_due_days', 14)),
            'paid_at' => $payment ? now() : null,
            'payment_id' => $payment?->id,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => $description,
            'quantity' => 1,
            'unit_price' => $amount,
            'line_total' => $total,
            'tax_rate' => $taxResult->taxRate,
            'tax_amount' => $taxResult->taxAmount,
        ]);

        return $invoice;
    }

    /**
     * Mark invoice as paid.
     */
    public function markAsPaid(Invoice $invoice, Payment $payment): void
    {
        $invoice->markAsPaid($payment);
    }

    /**
     * Mark invoice as void.
     */
    public function void(Invoice $invoice): void
    {
        $invoice->void();
    }

    /**
     * Generate PDF for an invoice.
     */
    public function generatePdf(Invoice $invoice): string
    {
        $invoice->load(['workspace', 'items']);

        $pdf = Pdf::loadView('commerce::pdf.invoice', [
            'invoice' => $invoice,
            'business' => config('commerce.tax.business'),
        ]);

        $filename = $this->getPdfPath($invoice);

        Storage::disk(config('commerce.pdf.storage_disk', 'local'))
            ->put($filename, $pdf->output());

        // Update invoice with PDF path
        $invoice->update(['pdf_path' => $filename]);

        return $filename;
    }

    /**
     * Get or generate PDF for invoice.
     */
    public function getPdf(Invoice $invoice): string
    {
        if ($invoice->pdf_path && Storage::disk(config('commerce.pdf.storage_disk', 'local'))->exists($invoice->pdf_path)) {
            return $invoice->pdf_path;
        }

        return $this->generatePdf($invoice);
    }

    /**
     * Get PDF download response.
     */
    public function downloadPdf(Invoice $invoice): StreamedResponse
    {
        $path = $this->getPdf($invoice);

        return Storage::disk(config('commerce.pdf.storage_disk', 'local'))
            ->download($path, "invoice-{$invoice->invoice_number}.pdf");
    }

    /**
     * Get PDF path for an invoice.
     */
    protected function getPdfPath(Invoice $invoice): string
    {
        $basePath = config('commerce.pdf.storage_path', 'invoices');

        return "{$basePath}/{$invoice->workspace_id}/{$invoice->invoice_number}.pdf";
    }

    /**
     * Send invoice email.
     */
    public function sendEmail(Invoice $invoice): void
    {
        if (! config('commerce.billing.send_invoice_emails', true)) {
            return;
        }

        // Generate PDF if not exists
        $this->getPdf($invoice);

        // Determine recipient email
        $recipientEmail = $invoice->billing_email
            ?? $invoice->workspace?->billing_email
            ?? $invoice->workspace?->owner()?->email;

        if (! $recipientEmail) {
            return;
        }

        Mail::to($recipientEmail)->queue(new InvoiceGenerated($invoice));
    }

    /**
     * Get invoices for a workspace.
     */
    public function getForWorkspace(Workspace $workspace, int $limit = 25): LengthAwarePaginator
    {
        return $workspace->invoices()
            ->with('items')
            ->latest()
            ->paginate($limit);
    }

    /**
     * Get unpaid invoices for a workspace.
     */
    public function getUnpaidForWorkspace(Workspace $workspace): Collection
    {
        return $workspace->invoices()
            ->pending()
            ->where('due_date', '>=', now())
            ->get();
    }

    /**
     * Get overdue invoices for a workspace.
     */
    public function getOverdueForWorkspace(Workspace $workspace): Collection
    {
        return $workspace->invoices()
            ->pending()
            ->where('due_date', '<', now())
            ->get();
    }
}
