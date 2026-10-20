<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordCashPaymentRequest;
use App\Http\Requests\ReviewPaymentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommitteePaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $this->payments->authorizeCommittee($request->user());
        $data = $request->validate(['status' => ['sometimes', Rule::in(['PENDING', 'VERIFIED', 'REJECTED'])]]);
        $payments = Payment::query()->where('status', $data['status'] ?? 'PENDING')
            ->with('invoice.person:id,full_name')->orderBy('submitted_at')->orderBy('id')->paginate(15);

        return response()->json($payments->through(fn (Payment $payment) => [
            ...$payment->displayData(), 'invoice_number' => $payment->invoice->invoice_number,
            'applicant_name' => $payment->invoice->person->full_name,
        ]));
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->payments->authorizeCommittee($request->user());
        $payment->load(['invoice.items', 'invoice.person:id,full_name', 'submitter.person:id,full_name', 'reviewer.person:id,full_name']);

        return response()->json(['data' => [
            ...$payment->displayData(),
            'submitted_by_name' => $payment->submitter->person->full_name,
            'reviewed_by_name' => $payment->reviewer?->person?->full_name,
            'can_review' => $payment->status === 'PENDING' && $payment->invoice->status === 'UNPAID'
                && $payment->submitted_by !== $request->user()->id && $payment->invoice->person_id !== $request->user()->person_id,
            'invoice' => [
                'id' => $payment->invoice->id, 'invoice_number' => $payment->invoice->invoice_number,
                'applicant_name' => $payment->invoice->person->full_name,
                'total' => $payment->invoice->total, 'status' => $payment->invoice->status,
                'due_date' => $payment->invoice->due_date->toDateString(),
                'items' => $payment->invoice->items->map(fn ($item) => ['description' => $item->description, 'amount' => $item->amount]),
            ],
        ]]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $this->payments->authorizeCommittee($request->user());
        $data = $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:100']]);
        $invoices = Invoice::query()->where('status', 'UNPAID')
            ->whereDoesntHave('payments', fn ($query) => $query->whereIn('status', ['PENDING', 'VERIFIED']))
            ->when(! empty($data['search']), fn ($query) => $query->where('invoice_number', 'like', '%'.$data['search'].'%'))
            ->with('person:id,full_name')->orderBy('issued_at')->orderBy('id')->paginate(15);

        return response()->json($invoices->through(fn (Invoice $invoice) => [
            'id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'total' => $invoice->total,
            'applicant_name' => $invoice->person->full_name, 'due_date' => $invoice->due_date->toDateString(),
        ]));
    }

    public function recordCash(RecordCashPaymentRequest $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validated();
        $payment = $this->payments->recordCash($invoice, $request->user(), (string) $data['amount'], $data['reference'] ?? null);

        return response()->json(['message' => 'Cash payment recorded. A different authorized user must verify it.', 'data' => $payment->displayData()], 201);
    }

    public function review(ReviewPaymentRequest $request, Payment $payment): JsonResponse
    {
        $data = $request->validated();
        $payment = $this->payments->review($payment, $request->user(), $data['decision'], $data['reason'] ?? null);

        return response()->json(['message' => 'Payment review recorded.', 'data' => $payment->displayData()]);
    }
}
