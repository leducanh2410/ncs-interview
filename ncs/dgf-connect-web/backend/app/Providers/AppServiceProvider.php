<?php

namespace App\Providers;

use App\Actions\Chat\AdminGroup\SyncAdminInternalParticipantsAction;
use App\Contracts\FaceRecognitionProviderInterface;
use App\Contracts\HolidayProviderContract;
use App\Enums\FaceRecognitionProviderName;
use App\Events\ShiftUserApproved;
use App\Events\ShiftUserRejected;
use App\Events\UserPaperApproved;
use App\Guards\AccessTokenGuard;
use App\Http\Middleware\CheckUserDisabled;
use App\Listeners\CheckSystemDocumentRequirementsListener;
use App\Listeners\CheckWorkplaceDocumentRequirementsListener;
use App\Listeners\ProcessAbsentNoticeOnShiftRejection;
use App\Models\CheckIn;
use App\Models\Group;
use App\Models\Role;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\UserInfo;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use App\Observers\Chat\AdminInternalUserObserver;
use App\Observers\Chat\ShiftUserObserver as ChatShiftUserObserver;
use App\Observers\CheckInAttendanceDeviationObserver;
use App\Observers\Group\CheckInObserver;
use App\Observers\Group\GroupObserver;
use App\Observers\Group\UserInfoObserver;
use App\Observers\Group\UserObserver;
use App\Observers\Group\WorkplaceObserver;
use App\Observers\Group\WorkplaceUserObserver;
use App\Observers\ShiftPlanDraftCleanupObserver;
use App\Observers\ShiftUserUrgentRegistrationObserver;
use App\Observers\UserInfoDocumentAuditObserver;
use App\Observers\UserInfoShortNameObserver;
use App\Observers\UserShortNameObserver;
use App\Observers\WorkplaceShiftAttendanceDeviationObserver;
use App\Services\AdminLeave\YasumiHolidayProvider;
use App\Services\Common\SalaryService;
use App\Services\FaceRecognition\RekognitionProvider;
use App\Services\FaceRecognition\RunPodProvider;
use App\Services\Reconciliation\AmazonKadouParser;
use App\Services\Reconciliation\FactoryTimesheetParser;
use App\Services\Reconciliation\ReconciliationParserRegistry;
use App\Services\Reconciliation\YamatoStaffDataParser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('salary-service', function ($app) {
            return $app->make(SalaryService::class);
        });

        $this->app->singleton(FaceRecognitionProviderInterface::class, function ($app) {
            $provider = config('services.face_recognition.provider');

            return match ($provider) {
                FaceRecognitionProviderName::RunPod->value => $app->make(RunPodProvider::class),
                FaceRecognitionProviderName::Rekognition->value => $app->make(RekognitionProvider::class),
                default => throw new InvalidArgumentException("Unsupported face recognition provider: {$provider}"),
            };
        });

        $this->app->bind(HolidayProviderContract::class, YasumiHolidayProvider::class);

        $this->app->singleton(ReconciliationParserRegistry::class, function ($app) {
            return new ReconciliationParserRegistry([
                $app->make(AmazonKadouParser::class),
                $app->make(YamatoStaffDataParser::class),
                $app->make(FactoryTimesheetParser::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::extend('access-token', function () {
            return new AccessTokenGuard(new LocalUserProvider());
        });

        // Register the broadcasting auth endpoint behind the custom access-token guard
        // (the app has no usable `web` guard, so the framework default would always fail).
        Broadcast::routes([
            'middleware' => ['auth:api', CheckUserDisabled::class],
        ]);
        require base_path('routes/channels.php');

        $this->configureRateLimiting();
        Workplace::observe(WorkplaceObserver::class);
        WorkplaceUser::observe(WorkplaceUserObserver::class);
        // UserInfoShortNameObserver must run before Group\UserInfoObserver: both listen to
        // UserInfo::saved, and the group observer's recompute reads short_name via
        // SyncUserGroupChatParticipantsAction — it must see the value this save cycle wrote.
        UserInfo::observe(UserInfoShortNameObserver::class);
        UserInfo::observe(UserInfoObserver::class);
        UserInfo::observe(UserInfoDocumentAuditObserver::class);
        CheckIn::observe(CheckInAttendanceDeviationObserver::class);
        WorkplaceShift::observe(WorkplaceShiftAttendanceDeviationObserver::class);
        CheckIn::observe(CheckInObserver::class);
        User::observe(UserObserver::class);
        User::observe(UserShortNameObserver::class);
        User::observe(AdminInternalUserObserver::class);
        Group::observe(GroupObserver::class);
        ShiftUser::observe(ShiftPlanDraftCleanupObserver::class);
        ShiftUser::observe(ChatShiftUserObserver::class);
        ShiftUser::observe(ShiftUserUrgentRegistrationObserver::class);

        $this->syncAdminInternalChatOnAccessChange();

        Event::listen(UserPaperApproved::class, [CheckSystemDocumentRequirementsListener::class, 'handle']);
        Event::listen(ShiftUserApproved::class, [CheckWorkplaceDocumentRequirementsListener::class, 'handle']);
        Event::listen(ShiftUserRejected::class, [ProcessAbsentNoticeOnShiftRejection::class, 'handle']);
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('register-limit', function ($request) {
            $key = sprintf('register|%s|%s', $request->email ?? 'no-email', $request->ip());

            return Limit::perMinute(10)->by($key);
        });

        RateLimiter::for('forgot-limit', function ($request) {
            $key = sprintf('forgot|%s|%s', $request->email ?? 'no-email', $request->ip());

            return Limit::perMinute(10)->by($key);
        });

        RateLimiter::for('restore-limit', function ($request) {
            $key = sprintf('restore|%s|%s', $request->key ?? 'no-key', $request->ip());

            return Limit::perMinute(10)->by($key);
        });

        RateLimiter::for('chat-send-limit', function ($request) {
            return Limit::perMinute(30)->by('chat-send|' . ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('chat-support-create-limit', function ($request) {
            return Limit::perMinute(5)->by('chat-support|' . ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('translate-limit', function ($request) {
            return Limit::perMinute(30)->by('translate|' . ($request->user()?->id ?: $request->ip()));
        });
    }

    /**
     * Membership of the internal admin group follows manager.auth, which changes either when a
     * user's roles change or when the permission itself moves between roles. Laratrust announces
     * both, so the group reconciles itself instead of every caller remembering to.
     */
    private function syncAdminInternalChatOnAccessChange(): void
    {
        $sync = static function (): void {
            if (SyncAdminInternalParticipantsAction::defer()) {
                return;
            }

            DB::afterCommit(static fn() => app(SyncAdminInternalParticipantsAction::class)());
        };

        User::roleAdded($sync);
        User::roleRemoved($sync);
        User::roleSynced($sync);
        Role::permissionAdded($sync);
        Role::permissionRemoved($sync);
        Role::permissionSynced($sync);
    }
}
