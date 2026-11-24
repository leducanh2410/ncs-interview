<?php

namespace App\Actions\User;

use App\Enums\Nationality;
use App\Helpers\NameHelper;
use App\Models\User;

class SyncShortNameAction
{
    public function __invoke(User $user, ?string $nationality): void
    {
        $shortName =
            $nationality === Nationality::SriLanka->value ? NameHelper::shortenSriLankaName($user->name) : null;

        if ($user->getRawOriginal('short_name') === $shortName) {
            return;
        }

        // Write only the derived column via the query builder: short_name is
        // internal derived data, so its write must not fire model events, and
        // when called from UserShortNameObserver::updated() the name columns
        // are still dirty (Eloquent fires `updated` before syncOriginal()), so
        // saving the whole model would rewrite them and re-enter the observer.
        $user
            ->newQuery()
            ->whereKey($user->getKey())
            ->toBase()
            ->update(['short_name' => $shortName]);

        $user->short_name = $shortName;
        $user->syncOriginalAttribute('short_name');
    }
}
