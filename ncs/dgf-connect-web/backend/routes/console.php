<?php

use App\Jobs\AutoCheckOut;
use App\Jobs\AutoCloseInactiveSupportChats;
use App\Jobs\AutoCloseShiftGroupChats;
use App\Jobs\AutoCreateSalaryRequest;
use App\Jobs\CollectWorkTime;
use App\Jobs\CreateShiftReport;
use App\Jobs\DeleteClosedSupportChats;
use App\Jobs\DeleteExpiredAccessTokens;
use App\Jobs\DeleteOldCheckInVerifications;
use App\Jobs\DeleteOldNotifications;
use App\Jobs\DeleteOldUserPaperItems;
use App\Jobs\DeleteStaleAdminPaperUploadDrafts;
use App\Jobs\DeleteStalePendingImportBatches;
use App\Jobs\DeleteStaleShiftPlanDrafts;
use App\Jobs\DocumentExpiryWarningReminder;
use App\Jobs\ExamResultCalculation;
use App\Jobs\NotifyRequiredExamActivationJob;
use App\Jobs\ProcessAntiSpoofingBatch;
use App\Jobs\PushNotificationToUser;
use App\Jobs\RecomputeFrequentUrgentWorkerGroup;
use App\Jobs\ShiftAdminAlert;
use App\Jobs\ShiftConfirmationReminder;
use App\Jobs\ShiftReminder;
use App\Jobs\SyncAdminInternalChatParticipants;
use App\Jobs\UserConfigActiveReminderJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new DeleteExpiredAccessTokens())->dailyAt('02:10');
Schedule::job(new DeleteOldNotifications())->dailyAt('02:11');
Schedule::job(new DeleteOldCheckInVerifications())->dailyAt('02:12');
Schedule::job(new DeleteOldUserPaperItems())->dailyAt('02:13');
Schedule::job(new DeleteStaleShiftPlanDrafts())->dailyAt('02:14');
Schedule::job(new DeleteClosedSupportChats())->dailyAt('02:15');
Schedule::job(new DeleteStaleAdminPaperUploadDrafts())->dailyAt('02:16');
Schedule::job(new SyncAdminInternalChatParticipants())->dailyAt('02:17');
Schedule::job(new DeleteStalePendingImportBatches())->dailyAt('02:18');

Schedule::job(new DocumentExpiryWarningReminder())->dailyAt('07:00');
Schedule::job(new CreateShiftReport())->dailyAt('07:45');
Schedule::job(new CollectWorkTime())->dailyAt('08:00');
// Membership expires as the trailing 90-day window slides, which no observer can react to,
// so the group is refreshed once a day. Recruitment targeting recomputes it inline anyway.
Schedule::job(new RecomputeFrequentUrgentWorkerGroup())->dailyAt('08:10')->withoutOverlapping(60);
Schedule::job(new ShiftConfirmationReminder())->dailyAt('08:57')->withoutOverlapping(60);

Schedule::job(new AutoCheckOut())->hourlyAt(5);
Schedule::job(new AutoCloseShiftGroupChats())->hourly()->withoutOverlapping(300);
Schedule::job(new AutoCloseInactiveSupportChats())->hourlyAt(10)->withoutOverlapping(300);

Schedule::job(new PushNotificationToUser())->everyMinute()->withoutOverlapping(60);
Schedule::job(new AutoCreateSalaryRequest())->everyMinute()->withoutOverlapping(3600);
Schedule::job(new ExamResultCalculation())->everyMinute()->withoutOverlapping(300);
Schedule::job(new NotifyRequiredExamActivationJob())->everyMinute()->withoutOverlapping(300);
// Schedule::job(new WarmUpFaceRecognition())->everyMinute()->withoutOverlapping(60);
Schedule::job(new ShiftReminder())->everyFiveMinutes()->withoutOverlapping(60);
Schedule::job(new ShiftAdminAlert())->everyFiveMinutes()->withoutOverlapping(60);
Schedule::job(new ProcessAntiSpoofingBatch())->everyMinute()->withoutOverlapping(300);

Schedule::job(new UserConfigActiveReminderJob())->monthlyOn(1, '10:00');
// Japan Post republishes KEN_ALL at the end of each month
Schedule::command('postal-codes:import')->monthlyOn(2, '03:30')->withoutOverlapping(3600);
