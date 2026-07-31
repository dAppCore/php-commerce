<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Mail;

use Core\Mod\Commerce\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Email notification sent when an invoice is generated.
 */
class InvoiceGenerated extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Invoice $invoice
    ) {
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->invoice->status === 'paid'
            ? "Your Host UK Invoice #{$this->invoice->invoice_number}"
            : "Invoice #{$this->invoice->invoice_number} - Payment Required";

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $this->invoice->load(['workspace', 'items']);

        return new Content(
            markdown: 'commerce::emails.invoice-generated',
            with: [
                'invoice' => $this->invoice,
                'workspace' => $this->invoice->workspace,
                'items' => $this->invoice->items,
                'isPaid' => $this->invoice->status === 'paid',
                'viewUrl' => route('hub.billing.invoices.view', $this->invoice),
                'downloadUrl' => route('hub.billing.invoices.pdf', $this->invoice),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        // Only attach PDF if it exists
        if (! $this->invoice->pdf_path) {
            return [];
        }

        $disk = config('commerce.pdf.storage_disk', 'local');

        if (! Storage::disk($disk)->exists($this->invoice->pdf_path)) {
            return [];
        }

        return [
            Attachment::fromStorageDisk($disk, $this->invoice->pdf_path)
                ->as("invoice-{$this->invoice->invoice_number}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
