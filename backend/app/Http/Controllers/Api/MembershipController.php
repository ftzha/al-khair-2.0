<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Services\InvoiceService;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    public function show(Request $request, MembershipService $memberships): JsonResponse
    {
        return response()->json(['data' => $memberships->overview($request->user())])
            ->header('Cache-Control', 'no-store, private');
    }

    public function renewal(Request $request, InvoiceService $invoices): JsonResponse
    {
        $membership = Membership::query()
            ->whereHas('member', fn ($query) => $query->where('person_id', $request->user()->person_id))
            ->firstOrFail();
        $invoice = $invoices->createRenewal($membership);

        return response()->json(['data' => ['id' => $invoice->id, 'invoice_number' => $invoice->invoice_number]])
            ->header('Cache-Control', 'no-store, private');
    }
}
