<?php

namespace App\Http\Middleware;

use App\Enums\UserPaperStatus;
use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckUserPaperStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user && $user->paper_status !== UserPaperStatus::Approved->value) {
            return ResponseHelper::forbidden();
        }

        return $next($request);
    }
}
