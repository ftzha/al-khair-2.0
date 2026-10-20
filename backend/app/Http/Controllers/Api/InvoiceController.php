<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $invoices = Invoice::query()->where('person_id', $request->user()->person_id)
            ->orderByDesc('issued_at')->orderByDesc('id')->paginate(15);

        return response()->json($invoices->through(fn (Invoice $invoice) => $this->summary($invoice)));
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless($invoice->person_id === $request->user()->person_id, 403, 'You cannot access another person\'s invoice.');
        $invoice->load('items');

        return response()->json(['data' => [
            ...$this->summary($invoice),
            'items' => $invoice->items->map(fn ($item) => [
                'id' => $item->id, 'fee_code' => $item->fee_code,
                'description' => $item->description, 'amount' => $item->amount,
            ]),
        ]]);
    }

    private function summary(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id, 'invoice_number' => $invoice->invoice_number,
            'registration_id' => $invoice->registration_id, 'type' => $invoice->type,
            'status' => $invoice->status, 'billing_year' => $invoice->billing_year,
            'currency' => $invoice->currency, 'total' => $invoice->total,
            'issued_at' => $invoice->issued_at->toISOString(),
            'due_date' => $invoice->due_date->toDateString(),
        ];
    }
}
