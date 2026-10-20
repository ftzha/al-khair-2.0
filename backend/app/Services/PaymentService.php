<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(private readonly FeeRateService $money) {}

    public function authorizeCommittee(User $actor): void
    {
        abort_unless($actor->status === 'ACTIVE' && in_array($actor->role, ['COMMITTEE', 'ADMIN'], true), 403, 'Committee access is required.');
    }

    public function submitBankTransfer(Invoice $invoice, User $actor, string $amount, string $reference, UploadedFile $receipt): Payment
    {
        abort_unless($actor->status === 'ACTIVE' && $invoice->person_id === $actor->person_id, 403);
        if (! $receipt->isValid() || $receipt->getSize() > 2097152 || ! in_array($receipt->getMimeType(), ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages(['receipt' => ['Upload a PDF, JPG or PNG receipt up to 2 MB.']]);
        }
        if (trim($reference) === '' || mb_strlen($reference) > 100) {
            throw ValidationException::withMessages(['reference' => ['Enter a transfer reference of up to 100 characters.']]);
        }

        $storedPath = null;
        try {
            return DB::transaction(function () use ($invoice, $actor, $amount, $reference, $receipt, &$storedPath) {
                $invoice = $this->lockPayableInvoice($invoice, $amount);
                $invoiceNumber = preg_replace('/[^A-Za-z0-9_-]/', '-', $invoice->invoice_number);
                $timestamp = now()->setTimezone('Asia/Kuala_Lumpur')->format('Ymd_His');
                $extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
                $filename = $invoiceNumber.'_receipt_'.$timestamp.'_'.Str::ulid().'.'.$extensions[$receipt->getMimeType()];
                $storedPath = $receipt->storeAs('receipts', $filename, 'local');
                if ($storedPath === false) {
                    throw new \RuntimeException('The receipt could not be stored.');
                }

                return $invoice->payments()->create([
                    'method' => 'BANK_TRANSFER', 'status' => 'PENDING', 'amount' => $amount,
                    'reference' => trim($reference), 'receipt_path' => $storedPath, 'receipt_mime' => $receipt->getMimeType(),
                    'submitted_by' => $actor->id, 'submitted_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            if (is_string($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }
    }

    public function recordCash(Invoice $invoice, User $actor, string $amount, ?string $reference = null): Payment
    {
        $this->authorizeCommittee($actor);
        if ($reference !== null && mb_strlen($reference) > 100) {
            throw ValidationException::withMessages(['reference' => ['The reference must not exceed 100 characters.']]);
        }

        return DB::transaction(function () use ($invoice, $actor, $amount, $reference) {
            $invoice = $this->lockPayableInvoice($invoice, $amount);

            return $invoice->payments()->create([
                'method' => 'CASH', 'status' => 'PENDING', 'amount' => $amount,
                'reference' => $reference === null ? null : trim($reference),
                'submitted_by' => $actor->id, 'submitted_at' => now(),
            ]);
        });
    }

    public function review(Payment $payment, User $actor, string $decision, ?string $reason = null): Payment
    {
        $this->authorizeCommittee($actor);
        $reason = trim($reason ?? '');
        if (! in_array($decision, ['VERIFY', 'REJECT'], true)) {
            throw ValidationException::withMessages(['decision' => ['Invalid payment decision.']]);
        }
        if ($decision === 'REJECT' && ($reason === '' || mb_strlen($reason) > 2000)) {
            throw ValidationException::withMessages(['reason' => ['A rejection reason of up to 2,000 characters is required.']]);
        }

        return DB::transaction(function () use ($payment, $actor, $decision, $reason) {
            // Always lock the invoice first, then its payment, across every payment write.
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            abort_if($actor->id === $payment->submitted_by || $actor->person_id === $invoice->person_id, 403, 'You cannot review a payment you submitted or a payment for your own invoice.');
            if ($payment->status !== 'PENDING' || $invoice->status !== 'UNPAID') {
                throw ValidationException::withMessages(['payment' => ['This payment is no longer awaiting review.']]);
            }
            if ($this->money->toCents($payment->amount) !== $this->money->toCents($invoice->total)) {
                throw ValidationException::withMessages(['amount' => ['The payment amount must equal the invoice total.']]);
            }
            if ($decision === 'VERIFY' && $invoice->payments()->where('status', 'VERIFIED')->exists()) {
                throw ValidationException::withMessages(['payment' => ['This invoice already has a verified payment.']]);
            }

            $payment->update([
                'status' => $decision === 'VERIFY' ? 'VERIFIED' : 'REJECTED',
                'reviewed_by' => $actor->id, 'reviewed_at' => now(),
                'verified_at' => $decision === 'VERIFY' ? now() : null,
                'rejection_reason' => $decision === 'REJECT' ? $reason : null,
            ]);
            if ($decision === 'VERIFY') {
                $invoice->update(['status' => 'PAID']);
            }

            return $payment;
        });
    }

    private function lockPayableInvoice(Invoice $invoice, string $amount): Invoice
    {
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
        if ($invoice->status !== 'UNPAID') {
            throw ValidationException::withMessages(['invoice' => ['Only unpaid invoices accept payments.']]);
        }
        if ($this->money->toCents($amount) !== $this->money->toCents($invoice->total) || $this->money->toCents($amount) === 0) {
            throw ValidationException::withMessages(['amount' => ['A full payment equal to the invoice total is required.']]);
        }
        if ($invoice->payments()->whereIn('status', ['PENDING', 'VERIFIED'])->exists()) {
            throw ValidationException::withMessages(['payment' => ['This invoice already has a pending or verified payment.']]);
        }

        return $invoice;
    }
}
