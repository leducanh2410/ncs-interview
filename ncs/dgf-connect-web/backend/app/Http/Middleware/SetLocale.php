<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $lang = $user->settings['lang'] ?? null;

            if ($lang && in_array($lang, Locale::values())) {
                App::setLocale($lang);
            }
        }

        return $next($request);
    }
}
