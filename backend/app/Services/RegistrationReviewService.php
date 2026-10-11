<?php

namespace App\Services;

use App\Models\Registration;
use App\Models\RegistrationReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistrationReviewService
{
    public function review(
        Registration $registration,
        User $reviewer,
        string $decision,
        ?string $reason = null
    ): RegistrationReview {
        abort_unless(
            // The reviewer must be active and have the role of either COMMITTEE or ADMIN.
            $reviewer->status === 'ACTIVE'
                && in_array($reviewer->role, ['COMMITTEE', 'ADMIN'], true),
            403,
            'You are not authorized to review registrations.'
        );

        if (!in_array(
            $decision,
            ['APPROVE', 'REJECT', 'REQUEST_CHANGES'],
            true
        )) {
            throw ValidationException::withMessages([
                'decision' => ['Invalid review decision.'],
            ]);
        }

        $reason = trim($reason ?? '');

        if ($decision !== 'APPROVE' && $reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['A reason is required for this decision.'],
            ]);
        }

        return DB::transaction(function () use (
            $registration,
            $reviewer,
            $decision,
            $reason
        ) {
            $registration = Registration::query()
                ->lockForUpdate()
                ->findOrFail($registration->id);

            if ($registration->status !== 'PENDING_REVIEW') {
                throw ValidationException::withMessages([
                    'registration' => ['This registration is not pending review.'],
                ]);
            }

            $submission = $registration->submissions()
                ->orderByDesc('revision')
                ->firstOrFail();

            // A reviewer must not review their own submission.
            if ($submission->submitted_by === $reviewer->id) {
                abort(403, 'You cannot review your own submission.');
            }

            $review = RegistrationReview::create([
                'registration_submission_id' => $submission->id,
                'reviewed_by' => $reviewer->id,
                'decision' => $decision,
                'reason' => $reason !== '' ? $reason : null,
                'reviewed_at' => now(),
            ]);

            $status = match ($decision) {
                'APPROVE' => 'APPROVED',
                'REJECT' => 'REJECTED',
                'REQUEST_CHANGES' => 'CHANGES_REQUESTED',
            };

            $registration->status = $status;

            // If the decision is APPROVE, set the approved_submission_id to the current submission's ID.
            if ($decision === 'APPROVE') {
                $registration->approved_submission_id = $submission->id;
            }

            $registration->save();

            return $review;
        });
    }
}
