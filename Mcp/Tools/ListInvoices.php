<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Mcp\Tools;

use Core\Mod\Commerce\Models\Invoice;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListInvoices extends Tool
{
    protected string $description = 'List invoices for a workspace with optional status filter';

    public function handle(Request $request): Response
    {
        $workspaceId = $request->input('workspace_id');
        $status = $request->input('status'); // paid, pending, overdue, void
        $limit = min($request->input('limit', 10), 50);

        $query = Invoice::with('order')
            ->where('workspace_id', $workspaceId)
            ->latest();

        if ($status) {
            $query->where('status', $status);
        }

        $invoices = $query->limit($limit)->get();

        $result = [
            'workspace_id' => $workspaceId,
            'count' => $invoices->count(),
            'invoices' => $invoices->map(fn ($invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'subtotal' => (float) $invoice->subtotal,
                'discount_amount' => (float) $invoice->discount_amount,
                'tax_amount' => (float) $invoice->tax_amount,
                'total' => (float) $invoice->total,
                'amount_paid' => (float) $invoice->amount_paid,
                'amount_due' => (float) $invoice->amount_due,
                'currency' => $invoice->currency,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'paid_at' => $invoice->paid_at?->toIso8601String(),
                'is_overdue' => $invoice->isOverdue(),
                'order_number' => $invoice->order?->order_number,
            ])->all(),
        ];

        return Response::text(json_encode($result, JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->integer('The workspace ID to list invoices for')->required(),
            'status' => $schema->string('Filter by status: paid, pending, overdue, void'),
            'limit' => $schema->integer('Maximum number of invoices to return (default 10, max 50)'),
        ];
    }
}
