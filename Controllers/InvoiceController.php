<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Controllers;

use Core\Front\Controller;
use Core\Mod\Commerce\Models\Invoice;
use Core\Mod\Commerce\Services\InvoiceService;
use Core\Tenant\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    public function __construct(
        protected InvoiceService $invoiceService
    ) {
    }

    /**
     * Download invoice PDF.
     */
    public function pdf(Request $request, Invoice $invoice): StreamedResponse|Response
    {
        // Verify the invoice belongs to the user's workspace
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403, 'Unauthorised');
        }

        $workspace = $user->defaultHostWorkspace();

        if (! $workspace || $invoice->workspace_id !== $workspace->id) {
            abort(403, 'You do not have access to this invoice.');
        }

        // Only allow downloading paid invoices
        if (! $invoice->isPaid()) {
            abort(403, 'This invoice cannot be downloaded yet.');
        }

        // Use the download method from InvoiceService
        return $this->invoiceService->downloadPdf($invoice);
    }

    /**
     * View invoice in browser.
     */
    public function view(Request $request, Invoice $invoice): Response
    {
        // Verify the invoice belongs to the user's workspace
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403, 'Unauthorised');
        }

        $workspace = $user->defaultHostWorkspace();

        if (! $workspace || $invoice->workspace_id !== $workspace->id) {
            abort(403, 'You do not have access to this invoice.');
        }

        // Generate PDF and get the content
        $path = $this->invoiceService->getPdf($invoice);
        $content = Storage::disk(config('commerce.pdf.storage_disk', 'local'))->get($path);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="invoice-'.$invoice->invoice_number.'.pdf"',
        ]);
    }
}
