<?php

namespace App\Actions\User\Schedule;

use App\Actions\ActionResult;
use App\Enums\AbsentNoticeStatus;
use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Mail\ShiftAbsentNoticeEmail;
use App\Models\ShiftAbsentNotice;
use App\Models\ShiftUser;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class CreateAbsentNoticeAction
{
    public function __invoke(ShiftUser $shiftUser, User $authUser): ActionResult
    {
        $shiftUser->loadMissing(['shift.workplace', 'shift.workplaceShift', 'user', 'workplacePosition']);

        if ($shiftUser->user_id !== $authUser->id) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        if ($shiftUser->status !== ShiftUserStatus::Approved->value) {
            return new ActionResult(error: ErrorCode::ShiftUserInvalidStatus, status: 400);
        }

        // A request the admin refused is not a request any more: the employee may ask again for the
        // same shift while the deadline still allows it. Any other existing notice still blocks.
        $existingNotice = $shiftUser->absentNotice;
        if ($existingNotice && $existingNotice->status !== AbsentNoticeStatus::Rejected) {
            return new ActionResult(error: ErrorCode::AbsentNoticeAlreadyExists, status: 409);
        }

        $shiftStart = Carbon::parse($shiftUser->shift->date . ' ' . $shiftUser->shift->workplaceShift->start_time);
        $deadline = $shiftStart->copy()->subHours((int) config('work.absent_notice.before_hour'));
        if (now()->gt($deadline)) {
            return new ActionResult(error: ErrorCode::AbsentNoticeDeadlinePassed, status: 400);
        }

        $absentNotice = DB::transaction(function () use ($shiftUser, $existingNotice) {
            if (!$existingNotice) {
                return ShiftAbsentNotice::create([
                    'shift_user_id' => $shiftUser->id,
                ]);
            }

            // One shift keeps one notice row, so a resubmission reopens the rejected one. created_at
            // moves to now because the admin list shows and sorts by it as the request time.
            $existingNotice
                ->forceFill([
                    'status' => AbsentNoticeStatus::Pending->value,
                    'processed_by_id' => null,
                    'processed_at' => null,
                    'created_at' => now(),
                ])
                ->save();

            return $existingNotice;
        });

        $notificationEmails = config('work.system_notification_emails', []);
        if (!empty($notificationEmails)) {
            Mail::to($notificationEmails)->queue(
                new ShiftAbsentNoticeEmail(
                    employeeName: $shiftUser->user->short_name,
                    shiftDate: $shiftUser->shift->date,
                    workplaceName: $shiftUser->shift->workplace->name,
                    shiftTime: $shiftUser->shift->workplaceShift->start_time .
                        ' - ' .
                        $shiftUser->shift->workplaceShift->end_time,
                    position: $shiftUser->workplacePosition?->name ?? '-'
                )
            );
        }

        return new ActionResult(status: 201, data: $absentNotice);
    }
}
