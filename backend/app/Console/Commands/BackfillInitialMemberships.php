<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Person;
use App\Services\MembershipService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

#[Signature('memberships:backfill-initial {--apply : Persist changes; without this flag each activation is previewed and rolled back}')]
#[Description('Local/testing only: backfill memberships from previously verified initial payments')]
class BackfillInitialMemberships extends Command
{
    public function handle(MembershipService $service): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Membership backfill is available only in local and testing environments.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $activated = 0;
        $skipped = 0;
        $failed = 0;
        $handledPeople = [];
        $this->info($apply ? 'Applying membership backfill.' : 'Preview only: activation changes will be rolled back.');

        Payment::query()->with('invoice:id,person_id')->where('status', 'VERIFIED')
            ->whereHas('invoice', fn ($query) => $query->where('type', 'INITIAL')->where('status', 'PAID'))
            ->chunkById(100, function ($payments) use ($service, $apply, &$activated, &$skipped, &$failed, &$handledPeople): void {
                foreach ($payments as $payment) {
                    try {
                        $personId = $payment->invoice->person_id;
                        $result = isset($handledPeople[$personId]) ? 'skipped' : $this->process($payment, $service, $apply);
                        $handledPeople[$personId] = true;
                        if ($result === 'skipped') {
                            $skipped++;
                            $this->line('Payment '.$payment->id.': skipped; membership already exists or was handled in this run.');
                        } else {
                            $activated++;
                            $this->line('Payment '.$payment->id.': '.($apply ? 'activated.' : 'would activate.'));
                        }
                    } catch (ValidationException $exception) {
                        $failed++;
                        $this->error('Payment '.$payment->id.': '.$exception->getMessage());
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->error('Payment '.$payment->id.': failed ('.class_basename($exception).'); changes rolled back.');
                    }
                }
            });

        $this->info(($apply ? 'Activated' : 'Would activate').": {$activated}; skipped: {$skipped}; failed: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function process(Payment $candidate, MembershipService $service, bool $apply): string
    {
        DB::beginTransaction();
        try {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($candidate->invoice_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($candidate->id);
            Person::query()->lockForUpdate()->findOrFail($invoice->person_id);
            $existing = Membership::query()
                ->whereHas('member', fn ($query) => $query->where('person_id', $invoice->person_id))
                ->lockForUpdate()->first();

            if ($existing !== null) {
                $result = 'skipped';
            } else {
                if ($payment->verified_at === null || $payment->verified_at->isFuture()
                    || $payment->verified_at->lt($payment->submitted_at)
                    || $payment->verified_at->lt($invoice->issued_at)) {
                    throw ValidationException::withMessages([
                        'payment' => ['The original verification timestamp must be present, not future-dated, and no earlier than submission or invoice issuance.'],
                    ]);
                }

                $membership = $service->activateInitialPayment($payment);
                $result = $membership->wasRecentlyCreated ? 'activated' : 'skipped';
                if ($result === 'activated') {
                    $service->syncStatus($membership);
                }
            }

            if ($apply) {
                DB::commit();
            } else {
                DB::rollBack();
            }

            return $result;
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }
}
