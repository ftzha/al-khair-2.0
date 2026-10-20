<?php

namespace App\Console\Commands;

use App\Models\Membership;
use App\Services\MembershipService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('memberships:sync-statuses')]
#[Description('Record overdue membership lapses without rewriting historical events')]
class SyncMembershipStatuses extends Command
{
    public function handle(MembershipService $service): int
    {
        $changed = 0;
        Membership::query()->where('status', '!=', 'CLOSED')
            ->chunkById(100, function ($memberships) use ($service, &$changed): void {
                foreach ($memberships as $membership) {
                    $status = $membership->status;
                    if ($service->syncStatus($membership)->status !== $status) {
                        $changed++;
                    }
                }
            });
        $this->info("Updated {$changed} membership statuses.");

        return self::SUCCESS;
    }
}
