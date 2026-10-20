<?php

namespace App\Console\Commands;

use App\Models\Membership;
use App\Services\InvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('memberships:generate-renewals')]
#[Description('Generate current-year annual renewal invoices during January 1–30')]
class GenerateRenewalInvoices extends Command
{
    public function handle(InvoiceService $invoices): int
    {
        $today = CarbonImmutable::now('Asia/Kuala_Lumpur');
        if ($today->month !== 1 || $today->day > 30) {
            $this->info('No renewal invoices generated outside January 1–30.');

            return self::SUCCESS;
        }
        $processed = 0;
        $failed = 0;
        Membership::query()->where('status', 'ACTIVE')
            ->whereHas('periods', fn ($query) => $query->where('billing_year', $today->year - 1))
            ->whereDoesntHave('periods', fn ($query) => $query->where('billing_year', $today->year))
            ->chunkById(100, function ($memberships) use ($invoices, &$processed, &$failed): void {
                foreach ($memberships as $membership) {
                    try {
                        $invoices->createRenewal($membership);
                        $processed++;
                    } catch (ValidationException $exception) {
                        $failed++;
                        $this->error('Membership '.$membership->id.': '.$exception->getMessage());
                    }
                }
            });
        $this->info("Processed {$processed} memberships; {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
