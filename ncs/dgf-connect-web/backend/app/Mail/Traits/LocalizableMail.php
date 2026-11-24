<?php

namespace App\Mail\Traits;

use App\Enums\Locale;
use App\Models\User;
use Illuminate\Support\Facades\View;

trait LocalizableMail
{
    protected ?string $userLocale = null;

    /**
     * Set the user's locale for email template selection.
     */
    public function forUser(User $user): self
    {
        $this->userLocale = $this->getUserLocale($user);

        return $this;
    }

    /**
     * Get the user's preferred locale from settings.
     */
    protected function getUserLocale(User $user): string
    {
        $settings = $user->settings ?? [];
        $locale = $settings['lang'] ?? config('app.locale', 'en');

        // Validate that the locale is supported
        $supportedLocales = array_map(fn($case) => $case->value, Locale::cases());

        if (!in_array($locale, $supportedLocales)) {
            $locale = config('app.locale', 'en');
        }

        return $locale;
    }

    /**
     * Get the localized template path.
     * For English, use the base path. For other languages, use locale subdirectory,
     * falling back to English when that locale has no template of its own.
     */
    protected function getLocalizedTemplate(string $baseTemplate): string
    {
        $locale = $this->userLocale ?? config('app.locale', 'en');

        // English templates are in the root directory
        if ($locale === Locale::English->value) {
            return $baseTemplate;
        }

        // Other locales are in subdirectories (e.g., paper.ja.approval_notification)
        $parts = explode('.', $baseTemplate);
        $parts[] = $parts[count($parts) - 1];
        $parts[count($parts) - 2] = $locale;
        $localizedTemplate = implode('.', $parts);

        return View::exists($localizedTemplate) ? $localizedTemplate : $baseTemplate;
    }

    /**
     * Get a translation in the user's locale.
     */
    protected function getLocalizedText(string $key, array $replace = []): string
    {
        $locale = $this->userLocale ?? config('app.locale', 'en');

        return trans($key, $replace, $locale);
    }
}
