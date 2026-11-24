<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckUserDisabled
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user && $user->is_disabled) {
            return ResponseHelper::forbidden(ErrorCode::AuthUserDisabled);
        }

        return $next($request);
    }
}
