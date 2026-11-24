<?php

namespace App\Enums;

use Illuminate\Support\Facades\App;

enum Locale: string
{
    use EnumTrait;

    case Vietnamese = 'vi';
    case English = 'en';
    case Japanese = 'ja';
    case Nepali = 'ne';
    case Sinhala = 'si';

    /**
     * Locales that need a translation row alongside content written in the currently
     * active app locale.
     *
     * Shared by notification-creation flows (CreateNotificationForUser, ApprovePapersAction,
     * UserService, ShiftReminder, ShiftConfirmationReminder, ...) whose base title/content is
     * built with __() under whatever locale SetLocale switched the request to (the acting
     * user's own settings.lang). Excluding that same active locale is what guarantees every
     * locale — including the one the base fields happen to be written in — ends up with a
     * row: change this to a fixed locale and one of these flows stops creating a row for
     * whichever locale it actually wrote its base content in.
     */
    public static function getTranslationLocales(): array
    {
        return self::excluding(App::getLocale());
    }

    /**
     * Locales the admin Notification management form's fixed contract asks for, independent
     * of who is signed in.
     *
     * Unlike getTranslationLocales(), this list backs StoreRequest validation and
     * NotificationService::createOrUpdateNotification, where the base title/content fields
     * are always the admin's own fixed base-language slot (see the frontend's
     * UI_SUPPORTED_LOCALES/getTranslationLocales, which likewise never varies by who is
     * signed in). Using the active app locale there would make an admin's own
     * settings.lang silently determine which title_{locale}/content_{locale} fields a
     * request even accepts.
     */
    public static function getBaseTranslationLocales(): array
    {
        return self::excluding(config('app.base_locale'));
    }

    private static function excluding(string $locale): array
    {
        return array_values(array_filter(self::values(), static fn($value) => $value !== $locale));
    }
}
