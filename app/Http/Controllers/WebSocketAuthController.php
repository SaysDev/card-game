<?php

namespace App\Http\Controllers;

use App\Services\WebSocketAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WebSocketAuthController extends Controller
{
    private WebSocketAuthService $authService;

    public function __construct(WebSocketAuthService $authService)
    {
        $this->authService = $authService;
    }

    public function getToken(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'error' => 'Unauthorized'
            ], 401);
        }

        $tokenData = $this->authService->generateTokenForUser($user);
        return response()->json($tokenData);
    }

    public function refreshToken(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'error' => 'Unauthorized'
            ], 401);
        }

        $token = $request->header('Authorization');
        if (!$token) {
            return response()->json([
                'error' => 'Token required'
            ], 400);
        }

        // Remove 'Bearer ' if present
        $token = str_replace('Bearer ', '', $token);

        $newToken = $this->authService->refreshToken($token);
        if (!$newToken) {
            return response()->json([
                'error' => 'Invalid token'
            ], 401);
        }

        return response()->json([
            'token' => $newToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email
            ]
        ]);
    }
} 