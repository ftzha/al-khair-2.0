<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitBankTransferRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless($invoice->person_id === $request->user()->person_id, 403);

        return response()->json(['data' => $invoice->payments()->get()->map(fn (Payment $payment) => $payment->displayData())]);
    }

    public function store(SubmitBankTransferRequest $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validated();
        $payment = $this->payments->submitBankTransfer($invoice, $request->user(), (string) $data['amount'], $data['reference'], $request->file('receipt'));

        return response()->json(['message' => 'Payment submitted for independent verification.', 'data' => $payment->displayData()], 201);
    }

    public function receipt(Request $request, Payment $payment): StreamedResponse
    {
        if ($payment->invoice->person_id !== $request->user()->person_id) {
            $this->payments->authorizeCommittee($request->user());
        }
        abort_if($payment->receipt_path === null || ! Storage::disk('local')->exists($payment->receipt_path), 404);
        $extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        abort_unless(isset($extensions[$payment->receipt_mime]), 404);

        $invoiceNumber = preg_replace('/[^A-Za-z0-9_-]/', '-', $payment->invoice->invoice_number);
        $timestamp = $payment->submitted_at->setTimezone('Asia/Kuala_Lumpur')->format('Ymd_His');
        $filename = $invoiceNumber.'_receipt_'.$timestamp.'_payment-'.$payment->id.'.'.$extensions[$payment->receipt_mime];

        return Storage::disk('local')->download($payment->receipt_path, $filename, [
            'Content-Type' => $payment->receipt_mime, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache',
        ]);
    }
}
