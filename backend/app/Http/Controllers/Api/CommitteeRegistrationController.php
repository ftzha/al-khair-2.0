<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewRegistrationRequest;
use App\Models\Registration;
use App\Services\RegistrationReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommitteeRegistrationController extends Controller
{
    public function __construct(
        private readonly RegistrationReviewService $reviewService
    ) {}

    private function authorizeCommittee(Request $request): void
    {
        abort_unless(
            $request->user()?->status === 'ACTIVE'
                && in_array(
                    $request->user()?->role,
                    ['COMMITTEE', 'ADMIN'],
                    true
                ),
            403
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCommittee($request);

        $registrations = Registration::query()
            ->where('status', 'PENDING_REVIEW')
            ->with('person:id,full_name')
            ->latest()
            ->paginate(15);

        return response()->json($registrations->through(fn ($registration) => [
            'id' => $registration->id,
            'status' => $registration->status,
            'type' => $registration->type,
            'applicant_name' => $registration->person->full_name,
            'created_at' => $registration->created_at,
        ]));
    }

    public function show(Request $request, Registration $registration): JsonResponse
    {
        $this->authorizeCommittee($request);

        $registration->load([
            'person:id,full_name',
            'submissions' => fn ($query) => $query
                ->orderBy('revision'),
            'submissions.reviews',
        ]);

        return response()->json([
            'data' => [
                'id' => $registration->id,
                'status' => $registration->status,
                'applicant_name' => $registration->person->full_name,
                'submissions' => $registration->submissions->map(fn ($submission) => [
                    'id' => $submission->id,
                    'revision' => $submission->revision,
                    'snapshot' => $submission->snapshot,
                    'submitted_at' => $submission->submitted_at,
                    'reviews' => $submission->reviews->map(fn ($review) => [
                        'decision' => $review->decision,
                        'reason' => $review->reason,
                        'reviewed_at' => $review->reviewed_at,
                    ]),
                ]),
            ],
        ]);
    }

    public function review(
        ReviewRegistrationRequest $request,
        Registration $registration
    ): JsonResponse {
        $validated = $request->validated();

        $review = $this->reviewService->review(
            $registration,
            $request->user(),
            $validated['decision'],
            $validated['reason'] ?? null
        );

        return response()->json([
            'message' => 'Registration reviewed successfully.',
            'data' => [
                'id' => $review->id,
                'decision' => $review->decision,
                'reason' => $review->reason,
                'reviewed_at' => $review->reviewed_at,
            ],
        ], 201);
    }
}
