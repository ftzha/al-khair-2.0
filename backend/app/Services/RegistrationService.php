<?php

namespace App\Services;

use App\Models\Registration;
use App\Models\RegistrationSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistrationService
{
    public function createDraft(User $user): Registration
    {
        if ($user->status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'account' => ['Your account is not active.'],
            ]);
        }

        return DB::transaction(function () use ($user) {
            $existing = Registration::query()
                ->where('person_id', $user->person_id)
                ->whereIn('status', [
                    'DRAFT',
                    'PENDING_REVIEW',
                    'CHANGES_REQUESTED',
                    'APPROVED',
                ])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'registration' => ['An existing registration already exists.'],
                ]);
            }

            return Registration::create([
                'person_id' => $user->person_id,
                'type' => 'NEW',
                'status' => 'DRAFT',
                'created_by' => $user->id,
                'draft_data' => [
                    'phone_number' => $user->person->phone_number,
                    'address' => $user->person->address,
                ],
            ]);
        });
    }
    
    public function updateDraft(
        Registration $registration,
        User $user,
        array $data
    ): Registration {
        if ($registration->person_id !== $user->person_id) {
            abort(403, 'You cannot edit another person\'s registration.');
        }

        if ($user->status !== 'ACTIVE') {
            abort(403, 'This account is not active.');
        }

        return DB::transaction(function () use ($registration, $data) {
            $registration = Registration::query()
                ->lockForUpdate()
                ->findOrFail($registration->id);

            if (!in_array(
                $registration->status,
                ['DRAFT', 'CHANGES_REQUESTED'],
                true
            )) {
                throw ValidationException::withMessages([
                    'registration' => ['This registration cannot be edited.'],
                ]);
            }

            $allowedFields = ['phone_number', 'address'];

            $updates = array_intersect_key(
                $data,
                array_flip($allowedFields)
            );

            $registration->update([
                'draft_data' => array_merge(
                    $registration->draft_data ?? [],
                    $updates
                ),
            ]);

            return $registration;
        });
    }

    public function submit(Registration $registration, User $user): RegistrationSubmission
    {
        if ($registration->person_id !== $user->person_id) {
            abort(403, 'You cannot submit another person\'s registration.');
        }

        if ($user->status !== 'ACTIVE') {
            abort(403, 'This account is not active.');
        }

        if (!$user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => ['Please verify your email before submitting.'],
            ]);
        }

        return DB::transaction(function () use ($registration, $user) {
            $registration = Registration::query()
                ->lockForUpdate()
                ->findOrFail($registration->id);

            if (!in_array($registration->status, ['DRAFT', 'CHANGES_REQUESTED'], true)) {
                throw ValidationException::withMessages([
                    'registration' => ['This registration cannot be submitted.'],
                ]);
            }

            $revision = ((int) $registration->submissions()->max('revision')) + 1;

            $person = $registration->person;
            $draft = $registration->draft_data ?? [];

            $submission = RegistrationSubmission::create([
                'registration_id' => $registration->id,
                'revision' => $revision,
                'snapshot' => [
                    'full_name' => $person->full_name,
                    'date_of_birth' => $person->date_of_birth?->toDateString(),
                    'phone_number' => $draft['phone_number'] ?? $person->phone_number,
                    'address' => $draft['address'] ?? $person->address,
                ],
                'submitted_by' => $user->id,
                'submitted_at' => now(),
            ]);

            $registration->update([
                'status' => 'PENDING_REVIEW',
            ]);

            return $submission;
        });
    }
}
