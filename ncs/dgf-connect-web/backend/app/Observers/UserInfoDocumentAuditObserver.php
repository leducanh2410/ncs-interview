<?php

namespace App\Observers;

use App\Models\UserInfo;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Text audit trail for the identity-document columns on user_infos (residence card
 * slots, passport, student card): who changed which column, from what, to what.
 *
 * Listening at the model layer instead of inside each action means every write
 * path is covered by construction — admin manual edit, employee document confirm,
 * admin OCR apply, and the CrossStaff sync. The `source` field carries the HTTP
 * method + route (so a sync via POST admin/users/{user}/from-cross-staff is
 * distinguishable from a manual PATCH) or the artisan command line for console runs.
 */
class UserInfoDocumentAuditObserver
{
    public const LOG_MESSAGE = 'audit.user_document_info';

    private const AUDITED_FIELDS = [
        'residence_number_1',
        'residence_expiry_date_1',
        'residence_status_1',
        'residence_number_2',
        'residence_expiry_date_2',
        'residence_status_2',
        'residence_number_3',
        'residence_expiry_date_3',
        'residence_status_3',
        'passport_number',
        'passport_expiry_date',
        'student_card_expiry_date',
    ];

    public function created(UserInfo $info): void
    {
        $changes = [];
        foreach (self::AUDITED_FIELDS as $field) {
            $new = $this->stringify($info->getAttribute($field));
            if ($new !== null) {
                $changes[$field] = ['old' => null, 'new' => $new];
            }
        }

        $this->log($info, $changes);
    }

    public function updated(UserInfo $info): void
    {
        $changes = [];
        foreach (array_intersect_key($info->getChanges(), array_flip(self::AUDITED_FIELDS)) as $field => $new) {
            $old = $this->stringify($info->getOriginal($field));
            $new = $this->stringify($new);
            if ($old !== $new) {
                $changes[$field] = ['old' => $old, 'new' => $new];
            }
        }

        $this->log($info, $changes);
    }

    /**
     * @param array<string, array{old: ?string, new: ?string}> $changes
     */
    private function log(UserInfo $info, array $changes): void
    {
        if (empty($changes)) {
            return;
        }

        Log::info(self::LOG_MESSAGE, [
            'user_id' => $info->user_id,
            'actor_id' => Auth::id(),
            'actor_email' => Auth::user()?->email,
            'source' => $this->source(),
            'changes' => $changes,
        ]);
    }

    /**
     * runningInConsole() alone is not enough to classify the write: PHPUnit and
     * queue workers are console processes that still dispatch real HTTP-shaped
     * requests, so a bound route is checked first and console is the fallback.
     */
    private function source(): string
    {
        $route = request()->route();
        if ($route !== null) {
            return request()->method() . ' ' . $route->uri();
        }

        if (app()->runningInConsole()) {
            $argv = implode(' ', (array) ($_SERVER['argv'] ?? []));

            return mb_trim('console ' . $argv);
        }

        return request()->method() . ' ' . request()->path();
    }

    /**
     * Normalizes to a comparable string; '' collapses to null so that a cleared
     * form field and a NULL column don't produce a noise entry against each other.
     */
    private function stringify(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }
}
