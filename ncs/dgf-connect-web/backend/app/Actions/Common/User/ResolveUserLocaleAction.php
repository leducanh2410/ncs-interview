<?php

namespace App\Actions\Common\User;

use App\Enums\Locale;
use App\Models\User;
use Illuminate\Support\Arr;

class ResolveUserLocaleAction
{
    /**
     * The locale a user should be addressed in. Queued jobs always run at the app default
     * locale, so per-user text must resolve the locale explicitly instead of relying on
     * App::getLocale().
     */
    public function execute(User $user): string
    {
        $lang = Arr::get($user->settings ?? [], 'lang');

        return is_string($lang) && in_array($lang, Locale::values(), true) ? $lang : config('app.base_locale');
    }
}
