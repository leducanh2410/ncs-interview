<?php

namespace App\Services;

use App\Actions\Common\Exam\ResolveExamRecipientsAction;
use App\Enums\ExamResultStatus;
use App\Enums\ExamType;
use App\Enums\Locale;
use App\Enums\UserPaperStatus;
use App\Models\Exam;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Throwable;

class ExamNotificationService
{
    public function __construct(private readonly ResolveExamRecipientsAction $resolveRecipients) {}

    /**
     * Resolve the users that should be notified when the given required exam
     * activates: the exam's audience (ResolveExamRecipientsAction), minus the
     * notification-only exclusions kept here:
     *
     * - User has `paper_status = approved` (mirrors document_read flow)
     * - User is not disabled
     * - User has not already satisfied the exam
     */
    public function getRecipientsForExam(Exam $exam): Collection
    {
        $query = User::query()
            ->with('devices')
            ->where('paper_status', UserPaperStatus::Approved->value)
            ->where('is_disabled', false)
            ->tap(fn(Builder $audience) => ($this->resolveRecipients)($audience, $exam));

        // Skip users who have already satisfied this exam. For external_form_prep,
        // that's `status = Completed` (external form done) — `score >= min_score`
        // would be true the moment a user merely starts, since min_score defaults
        // to 0 and a fresh ExamResult also starts at score = 0.
        $query->whereDoesntHave('examResults', function ($q) use ($exam) {
            $q->where('exam_id', $exam->id);
            if ($exam->type === ExamType::ExternalFormPrep) {
                $q->where('status', ExamResultStatus::Completed->value);
            } else {
                $q->where('score', '>=', $exam->min_score);
            }
        });

        return $query->get();
    }

    /**
     * Build the FCM payload for the activation notification. The mobile client
     * parses this in the same shape as `document_read_required`.
     */
    public function serializeExam(Exam $exam, ?string $locale = null): array
    {
        $locale = $locale ?? App::getLocale();

        return [
            'id' => $exam->id,
            'title' => $exam->translation($locale),
            'type' => $exam->type->value,
            'is_required' => (bool) $exam->is_required,
            'min_score' => (int) $exam->min_score,
            'time_limit' => (int) $exam->time_limit,
            'start_at' => $exam->start_at?->format('Y-m-d H:i:s'),
            'end_at' => $exam->is_required ? null : $exam->end_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Dispatch the activation notification to every device of every recipient.
     * FCM multicast sends are limited to 500 registration tokens per request,
     * so large recipient sets must be split into multiple sends.
     *
     * Recipients are grouped by their saved locale first (this runs from a
     * queued job, which never goes through the `SetLocale` middleware, and a
     * single global locale couldn't represent a multi-language recipient set
     * anyway), and a notification + `pending_exams` payload is built per
     * locale group before that group's tokens are chunked into batches.
     */
    public function sendActivationNotification(Exam $exam, Collection $users, ?Messaging $messaging): bool
    {
        $usersByLocale = $this->groupUsersByLocale($users);

        $allTokens = $users
            ->flatMap(fn(User $user) => $user->devices->pluck('fcm_token'))
            ->filter()
            ->unique()
            ->values();

        if ($allTokens->isEmpty()) {
            Log::info('No FCM devices to notify for activated exam', [
                'exam_id' => $exam->id,
            ]);

            return true;
        }

        if (!$messaging) {
            Log::info('Skipped required-exam activation FCM because Firebase messaging is unavailable', [
                'exam_id' => $exam->id,
                'user_count' => $users->count(),
                'device_count' => $allTokens->count(),
            ]);

            return true;
        }

        try {
            if (app()->environment() !== 'local') {
                // Loaded once so translation() (called per locale group below,
                // twice per group via buildActivationMessage()/serializeExam())
                // reuses it instead of re-querying exam_translations each time.
                $exam->loadMissing('translations');

                foreach ($usersByLocale as $locale => $localeUsers) {
                    $tokens = $localeUsers
                        ->flatMap(fn(User $user) => $user->devices->pluck('fcm_token'))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    if (empty($tokens)) {
                        continue;
                    }

                    $message = $this->buildActivationMessage($exam, $locale);

                    foreach (array_chunk($tokens, 500) as $tokenChunk) {
                        $messaging->sendMulticast($message, $tokenChunk);
                    }
                }
            }
            Log::info('Sent required-exam activation notification', [
                'exam_id' => $exam->id,
                'user_count' => $users->count(),
                'device_count' => $allTokens->count(),
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to send required-exam activation notification', [
                'exam_id' => $exam->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    private function buildActivationMessage(Exam $exam, string $locale): CloudMessage
    {
        $title = $exam->translation($locale);

        return CloudMessage::new()
            ->withNotification([
                'title' => __('lang.required_exam.title', [], $locale),
                'body' => __('lang.required_exam.content', ['title' => $title], $locale),
            ])
            ->withData([
                'type' => 'exam_required',
                'pending_exams' => json_encode([$this->serializeExam($exam, $locale)], JSON_THROW_ON_ERROR),
            ]);
    }

    /**
     * Group recipients by their saved `settings.lang`, falling back to the
     * app's configured default locale for users without a saved preference.
     *
     * @return Collection<string, Collection<int, User>>
     */
    private function groupUsersByLocale(Collection $users): Collection
    {
        $defaultLocale = config('app.locale');

        return $users->groupBy(function (User $user) use ($defaultLocale) {
            $lang = Arr::get($user->settings, 'lang');

            return $lang && in_array($lang, Locale::values(), true) ? $lang : $defaultLocale;
        });
    }
}
