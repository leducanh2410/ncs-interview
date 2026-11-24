<?php

namespace App\Helpers;

use Illuminate\Http\Request;

class RequestHelper
{
    public static function getTokenFromRequest(Request $request): ?string
    {
        $token = $request->cookie('access_token');
        if (empty($token)) {
            $token = $request->bearerToken();
        }

        return $token;
    }
}
