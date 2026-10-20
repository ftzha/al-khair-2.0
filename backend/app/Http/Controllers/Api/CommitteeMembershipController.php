<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CloseMembershipRequest;
use App\Models\Membership;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommitteeMembershipController extends Controller
{
    public function index(Request $request, MembershipService $service): JsonResponse
    {
        $service->authorizeCommittee($request->user());
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($service->committeeIndex($request->user(), trim($data['search'] ?? '')))
            ->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, Membership $membership, MembershipService $service): JsonResponse
    {
        return response()->json(['data' => $service->committeeDetail($request->user(), $membership)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function close(CloseMembershipRequest $request, Membership $membership, MembershipService $service): JsonResponse
    {
        $service->close($membership, $request->user(), $request->validated('reason'));

        return response()->json(['message' => 'Membership closed.', 'data' => $service->committeeDetail($request->user(), $membership)])
            ->header('Cache-Control', 'no-store, private');
    }
}
