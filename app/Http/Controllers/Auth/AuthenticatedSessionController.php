<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\WebSocketAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    private WebSocketAuthService $wsAuth;

    public function __construct(WebSocketAuthService $wsAuth)
    {
        $this->wsAuth = $wsAuth;
    }

    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Generate WebSocket token
        if ($request->user()) {
            $tokenData = $this->wsAuth->generateUserToken($request->user());
            $request->user()->update(['ws_token' => $tokenData['token']]);
            
            // Load active game to make it available in Inertia props
            $request->user()->load(['activeGame' => function($query) {
                $query->with(['players.user']);
            }]);
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        // Clear WebSocket token
        if ($request->user()) {
            $request->user()->update(['ws_token' => null]);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
