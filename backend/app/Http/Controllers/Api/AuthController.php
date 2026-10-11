<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterUserRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function register(
        RegisterUserRequest $request,
        AuthService $authService
    ): JsonResponse {
        $user = $authService->register($request->validated());

        return response()->json([
            'message' => 'Account registered successfully.',
            'data' => [
                'id' => $user->id,
                'full_name' => $user->person->full_name,
                'email' => $user->email,
                'role' => $user->role,
                'email_verified' => false,
            ],
        ], 201);
    }
    
    public function login(
        LoginRequest $request,
        AuthService $authService
    ): JsonResponse {
        $result = $authService->login($request->validated());

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'token' => $result['token'],
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $result['user']->id,
                    'full_name' => $result['user']->person->full_name,
                    'email' => $result['user']->email,
                    'role' => $result['user']->role,
                    'email_verified' => $result['user']->email_verified_at !== null,
                ],
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('person');

        return response()->json([
            'data' => [
                'id' => $user->id,
                'full_name' => $user->person->full_name,
                'email' => $user->email,
                'role' => $user->role,
                'email_verified' => $user->email_verified_at !== null,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
