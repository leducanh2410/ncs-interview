<?php

namespace App\Jobs;

use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Models\Exam;
use App\Services\ExamNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class NotifyRequiredExamActivationJob implements ShouldQueue
{
    use Queueable;

    /**
     * The scheduler's withoutOverlapping() only guards the dispatch step of a
     * queued job, so consecutive ticks can still run concurrently on workers.
     * Skip (don't release) an instance while another is already running.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('notify-required-exam-activation'))->dontRelease()->expireAfter(300)];
    }

    /**
     * Execute the job.
     *
     * Picks up required exams whose start_at moment has been reached and that
     * have not yet been notified, fans out an FCM notification per exam, and
     * marks the exam as notified so the next tick skips it.
     */
    public function handle(
        ExamNotificationService $service,
        ?ResolveFirebaseMessagingAction $resolveMessaging = null
    ): void {
        $now = now();

        $exams = Exam::query()
            ->where('is_required', true)
            ->whereNull('notified_at')
            ->where(function ($query) use ($now) {
                $query->whereNull('start_at')->orWhere('start_at', '<=', $now);
            })
            ->take(50)
            ->get();

        if ($exams->isEmpty()) {
            return;
        }

        $messaging = ($resolveMessaging ?? app(ResolveFirebaseMessagingAction::class))->execute();

        foreach ($exams as $exam) {
            // Claim the exam BEFORE sending. The FCM fan-out can outlast a
            // scheduler tick, so a later job instance would otherwise see
            // notified_at still NULL mid-send and notify everyone again. The
            // conditional update lets exactly one instance win the claim.
            $claimed = Exam::query()
                ->whereKey($exam->id)
                ->whereNull('notified_at')
                ->update(['notified_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            $recipients = $service->getRecipientsForExam($exam);
            $sent = $service->sendActivationNotification($exam, $recipients, $messaging);

            if (!$sent) {
                // Release the claim so the next tick retries the send. Query
                // update, not $exam->update(): the in-memory model still has
                // notified_at = null, so Eloquent would see nothing dirty.
                Exam::query()
                    ->whereKey($exam->id)
                    ->update(['notified_at' => null]);

                Log::warning('Required exam activation notification failed; will retry on next scheduler tick', [
                    'exam_id' => $exam->id,
                    'recipients' => $recipients->count(),
                ]);

                continue;
            }

            Log::info('Required exam activation processed', [
                'exam_id' => $exam->id,
                'recipients' => $recipients->count(),
            ]);
        }
    }
}
