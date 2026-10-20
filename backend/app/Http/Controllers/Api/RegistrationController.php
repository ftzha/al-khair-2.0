<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateRegistrationDraftRequest;
use App\Models\Registration;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrationController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registrationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $registrations = Registration::query()
            ->where('person_id', $request->user()->person_id)
            ->latest()
            ->get([
                'id',
                'type',
                'status',
                'created_at',
                'updated_at',
            ]);

        return response()->json([
            'data' => $registrations,
        ]);
    }

    public function show(Request $request, Registration $registration): JsonResponse
    {
        $this->ensureOwner($request->user(), $registration);

        $registration->load([
            'person:id,full_name,date_of_birth',
            'submissions' => fn ($query) => $query
                ->select([
                    'id',
                    'registration_id',
                    'revision',
                    'snapshot',
                    'submitted_at',
                ])
                ->orderBy('revision'),
            'submissions.reviews' => fn ($query) => $query->orderBy('reviewed_at')->orderBy('id'),
        ]);

        return response()->json([
            'data' => [
                'id' => $registration->id,
                'type' => $registration->type,
                'status' => $registration->status,
                'draft_data' => $registration->draft_data,
                'applicant' => [
                    'full_name' => $registration->person->full_name,
                    'date_of_birth' => $registration->person->date_of_birth?->toDateString(),
                ],
                'submissions' => $registration->submissions->map(fn ($submission) => [
                    'id' => $submission->id,
                    'registration_id' => $submission->registration_id,
                    'revision' => $submission->revision,
                    'snapshot' => $submission->snapshot,
                    'submitted_at' => $submission->submitted_at,
                    'reviews' => $submission->reviews->map(fn ($review) => [
                        'decision' => $review->decision,
                        'reason' => $review->reason,
                        'reviewed_at' => $review->reviewed_at,
                    ]),
                ]),
                'created_at' => $registration->created_at,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $registration = $this->registrationService
            ->createDraft($request->user());

        return response()->json([
            'message' => 'Registration draft created.',
            'data' => [
                'id' => $registration->id,
                'status' => $registration->status,
                'draft_data' => $registration->draft_data,
            ],
        ], 201);
    }

    public function updateDraft(
        UpdateRegistrationDraftRequest $request,
        Registration $registration
    ): JsonResponse {
        $updated = $this->registrationService->updateDraft(
            $registration,
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'message' => 'Registration draft updated.',
            'data' => [
                'id' => $updated->id,
                'status' => $updated->status,
                'draft_data' => $updated->draft_data,
            ],
        ]);
    }

    public function submit(
        Request $request,
        Registration $registration
    ): JsonResponse {
        $submission = $this->registrationService->submit(
            $registration,
            $request->user()
        );

        return response()->json([
            'message' => 'Registration submitted for review.',
            'data' => [
                'registration_id' => $submission->registration_id,
                'revision' => $submission->revision,
                'submitted_at' => $submission->submitted_at,
            ],
        ], 201);
    }

    private function ensureOwner(
        User $user,
        Registration $registration
    ): void {
        abort_unless(
            $registration->person_id === $user->person_id,
            403,
            'You cannot access another person\'s registration.'
        );
    }
}
