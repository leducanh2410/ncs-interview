<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Enums\RoleName;
use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckNeedChangePassword
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user && ($user->hasRole(RoleName::StaffManager->value) || $user->hasRole(RoleName::Staff->value)) && $user->temp_password) {
            return ResponseHelper::forbidden(ErrorCode::AuthUserDisabled);
        }

        return $next($request);
    }
}
