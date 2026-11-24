<?php

namespace App\Http\Middleware;

use App\Models\GuestSupportSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthGuestSupport
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if ($bearer === null) {
            return response()->json(['error' => 'E_UNAUTHORIZED'], 401);
        }

        $hash = hash_hmac('sha256', $bearer, config('app.key'));
        $session = GuestSupportSession::query()->where('session_token_hash', $hash)->first();

        if ($session === null) {
            return response()->json(['error' => 'E_UNAUTHORIZED'], 401);
        }

        $routeId = $request->route('id');
        if ($routeId !== null && $session->conversation_id !== (int) $routeId) {
            return response()->json(['error' => 'E_FORBIDDEN'], 403);
        }

        $request->attributes->set('guestSession', $session);

        return $next($request);
    }
}
