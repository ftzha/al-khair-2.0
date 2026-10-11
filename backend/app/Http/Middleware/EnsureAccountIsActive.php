<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->status !== 'ACTIVE') {
            return response()->json([
                'message' => 'This account is not active.',
            ], 403);
        }

        return $next($request);
    }
}
