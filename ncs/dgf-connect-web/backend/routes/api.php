<?php

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UserPaperStepKey;
use App\Http\Controllers\Admin\AdminLeaveRequestController;
use App\Http\Controllers\Admin\AdminPaperUploadController;
use App\Http\Controllers\Admin\Attendance\CheckInController as AdminAttendanceCheckInController;
use App\Http\Controllers\Admin\Attendance\HistoryController as AdminAttendanceHistoryController;
use App\Http\Controllers\Admin\BankAccountController as AdminBankAccountController;
use App\Http\Controllers\Admin\Chat\BannedKeywordController as AdminChatBannedKeywordController;
use App\Http\Controllers\Admin\Chat\ConversationController as AdminChatConversationController;
use App\Http\Controllers\Admin\Chat\MessageController as AdminChatMessageController;
use App\Http\Controllers\Admin\Chat\PinMessageController as AdminChatPinMessageController;
use App\Http\Controllers\Admin\Chat\SupportController as AdminChatSupportController;
use App\Http\Controllers\Admin\Chat\SupportTypeController as AdminChatSupportTypeController;
use App\Http\Controllers\Admin\CheckInController as AdminCheckInController;
use App\Http\Controllers\Admin\CompanyHolidayController;
use App\Http\Controllers\Admin\ConfigController;
use App\Http\Controllers\Admin\CsvExportController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\DocumentReadConfigController;
use App\Http\Controllers\Admin\ExamController;
use App\Http\Controllers\Admin\ExamResultController;
use App\Http\Controllers\Admin\ExportHistoryController;
use App\Http\Controllers\Admin\FixedScheduleChangeConfigController;
use App\Http\Controllers\Admin\FixedScheduleChangeRequestController;
use App\Http\Controllers\Admin\FixedScheduleController;
use App\Http\Controllers\Admin\ForecastController;
use App\Http\Controllers\Admin\GroupController;
use App\Http\Controllers\Admin\ImportHistoryController;
use App\Http\Controllers\Admin\Manage\CheckInController as AdminManageCheckInController;
use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\Admin\PaperReconciliationController;
use App\Http\Controllers\Admin\PdfExportController;
use App\Http\Controllers\Admin\PdfTemplateController;
use App\Http\Controllers\Admin\PollController;
use App\Http\Controllers\Admin\PostalCodeController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\TranslationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserFaceController;
use App\Http\Controllers\Admin\WorkplaceController;
use App\Http\Controllers\Admin\WorkplaceDocumentReadConfigController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Chat\ChatAttachmentController;
use App\Http\Controllers\Chat\ChatPresenceController;
use App\Http\Controllers\Manage\AbsentNoticeController;
use App\Http\Controllers\Manage\AnnouncementController;
use App\Http\Controllers\Manage\ChatController as ManageChatController;
use App\Http\Controllers\Manage\NotificationController;
use App\Http\Controllers\Manage\ProfileController;
use App\Http\Controllers\Manage\ReconciliationController;
use App\Http\Controllers\Manage\SalaryController;
use App\Http\Controllers\Manage\ScheduleController;
use App\Http\Controllers\Manage\ShiftController;
use App\Http\Controllers\Manage\ShiftOrderImportController;
use App\Http\Controllers\Manage\ShiftPlanningController;
use App\Http\Controllers\Manage\ShiftReportController;
use App\Http\Controllers\Manage\WorkHistoryController;
use App\Http\Controllers\Manage\WorkHistoryEditRequestController;
use App\Http\Controllers\User\AnnouncementController as UserAnnouncementController;
use App\Http\Controllers\User\BankAccountController as UserBankAccountController;
use App\Http\Controllers\User\ChatConversationController;
use App\Http\Controllers\User\ChatMessageController;
use App\Http\Controllers\User\ChatSupportController;
use App\Http\Controllers\User\CheckInController;
use App\Http\Controllers\User\DocumentController as UserDocumentController;
use App\Http\Controllers\User\DocumentReadConfirmationController;
use App\Http\Controllers\User\DocumentStepController;
use App\Http\Controllers\User\ExamController as UserExamController;
use App\Http\Controllers\User\FixedScheduleController as UserFixedScheduleController;
use App\Http\Controllers\User\NotificationController as UserNotificationController;
use App\Http\Controllers\User\PollResponseController;
use App\Http\Controllers\User\SalaryController as UserSalaryController;
use App\Http\Controllers\User\ScheduleController as UserScheduleController;
use App\Http\Controllers\User\UserController as GeneralUserController;
use App\Http\Controllers\User\WorkplaceController as UserWorkplaceController;
use App\Http\Middleware\CheckUserDisabled;
use App\Http\Middleware\CheckUserPaperStatus;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/login/admin', [AuthController::class, 'loginAdmin']);
Route::post('/auth/login/admin/tfa', [AuthController::class, 'loginAdminTfa']);

Route::post('/auth/reset-password/request', [AuthController::class, 'requestPasswordReset'])->middleware(
    'throttle:forgot-limit'
);
Route::post('/auth/reset-password/process', [AuthController::class, 'passwordReset']);
Route::post('/user/auth/reset-password/request', [AuthController::class, 'requestPasswordResetUser'])->middleware(
    'throttle:forgot-limit'
);
Route::post('/user/auth/reset-password/process', [AuthController::class, 'passwordResetUser']);

Route::post('/auth/register', [AuthController::class, 'registerUser']);
Route::post('/auth/register/request', [AuthController::class, 'requestRegisterOtp'])->middleware(
    'throttle:register-limit'
);
Route::post('/auth/register/verify', [AuthController::class, 'verifyRegisterOtp']);
Route::post('/auth/register/set-password', [AuthController::class, 'storeTempUser']);
Route::post('/auth/restore', [AuthController::class, 'restoreAccount'])->middleware('throttle:restore-limit');

Route::group(['middleware' => ['auth:api', CheckUserDisabled::class, SetLocale::class]], function () {
    Route::get('/auth/check', [AuthController::class, 'authCheck']);
    Route::delete('/auth/logout', [AuthController::class, 'logout']);

    Route::group(['prefix' => 'admin'], function () {
        Route::group(
            ['prefix' => 'master', 'middleware' => ['permission:' . PermissionName::ManagerAuth->value]],
            function () {
                Route::get('/', [MasterDataController::class, 'getAll']);
                Route::get('/users/search', [MasterDataController::class, 'searchUser']);
                Route::get('/roles', [MasterDataController::class, 'getRoles'])->middleware(
                    'permission:' . PermissionName::UserAssignRole->value
                );
            }
        );

        // AI-assisted translation of admin-entered content; any role that can author
        // translatable content (notification, poll, document read config, exam) may call it.
        Route::post('/translate', [TranslationController::class, 'translate'])->middleware([
            'permission:' .
            implode('|', [
                PermissionName::NotificationCreate->value,
                PermissionName::NotificationEdit->value,
                PermissionName::PollCreate->value,
                PermissionName::PollEdit->value,
                PermissionName::ConfigEdit->value,
                PermissionName::ExamCreate->value,
                PermissionName::ExamEdit->value,
                PermissionName::AnnouncementCreate->value,
                PermissionName::AnnouncementEdit->value,
            ]),
            'throttle:translate-limit',
        ]);

        Route::group(['prefix' => 'roles'], function () {
            Route::get('/', [RoleController::class, 'index'])->middleware(
                'permission:' . PermissionName::RoleList->value
            );
            Route::post('/', [RoleController::class, 'store'])->middleware(
                'permission:' . PermissionName::RoleCreate->value
            );
            Route::get('/{role}', [RoleController::class, 'show'])->middleware(
                'permission:' . PermissionName::RoleList->value
            );
            Route::put('/{role}', [RoleController::class, 'update'])->middleware(
                'permission:' . PermissionName::RoleEdit->value
            );
            Route::delete('/{role}', [RoleController::class, 'destroy'])->middleware(
                'permission:' . PermissionName::RoleDelete->value
            );
        });

        Route::get('/users', [UserController::class, 'index'])->middleware(
            'permission:' . PermissionName::UserList->value
        );
        Route::post('/users', [UserController::class, 'createManager'])->middleware(
            'permission:' . PermissionName::UserCreate->value
        );
        Route::get('/users/{id}', [UserController::class, 'show'])->middleware(
            'permission:' . PermissionName::UserList->value
        );
        Route::patch('/users/{id}', [UserController::class, 'update'])->middleware(
            'permission:' . PermissionName::UserEdit->value
        );
        Route::post('/users/{id}/disable', [UserController::class, 'disableUser'])->middleware(
            'permission:' . PermissionName::UserDisable->value
        );
        Route::post('/users/{id}/enable', [UserController::class, 'enableUser'])->middleware(
            'permission:' . PermissionName::UserEnable->value
        );
        Route::post('/users/{user}/restore', [UserController::class, 'restoreDeletedUser'])->middleware(
            'permission:' . PermissionName::UserRestore->value
        );
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware(
            'permission:' . PermissionName::UserResetPassword->value
        );
        Route::get('/users/{user}/lock-histories', [UserController::class, 'getLockHistories'])->middleware(
            'permission:' . PermissionName::UserList->value
        );
        Route::get('/users/{user}/upcoming-shifts', [UserController::class, 'getUpcomingShifts'])->middleware(
            'permission:' . PermissionName::UserDisable->value
        );
        Route::post('/users/{user}/to-cross-staff', [UserController::class, 'syncToCrossStaff'])->middleware(
            'permission:' . PermissionName::UserSync->value
        );
        Route::post('/users/{user}/from-cross-staff', [UserController::class, 'syncFromCrossStaff'])->middleware(
            'permission:' . PermissionName::UserSync->value
        );

        Route::get('/users/{id}/roles', [UserController::class, 'getRoles'])->middleware(
            'permission:' . PermissionName::UserList->value
        );
        Route::post('/users/{id}/roles', [UserController::class, 'assignRoles'])->middleware(
            'permission:' . PermissionName::UserAssignRole->value
        );
        Route::delete('/users/{id}/roles', [UserController::class, 'removeRole'])->middleware(
            'permission:' . PermissionName::UserAssignRole->value
        );

        Route::get('/users/{id}/images', [UserController::class, 'getImages'])->middleware(
            'permission:' . PermissionName::UserImageList->value
        );
        Route::post('/users/{id}/images', [UserController::class, 'uploadImage'])->middleware(
            'permission:' . PermissionName::UserImageUpload->value
        );
        Route::delete('/users/{userId}/images/{id}', [UserController::class, 'deleteImage'])->middleware(
            'permission:' . PermissionName::UserImageDelete->value
        );

        Route::get('/users/{id}/devices', [UserController::class, 'getDevices'])->middleware(
            'permission:' . PermissionName::UserDeviceList->value
        );
        Route::delete('/users/{userId}/devices/{id}', [UserController::class, 'deleteDevice'])->middleware(
            'permission:' . PermissionName::UserDeviceDelete->value
        );

        Route::get('/users/{id}/workplaces', [UserController::class, 'getUserWorkplaces'])->middleware(
            'permission:' . PermissionName::UserWorkplaceList->value
        );
        Route::post('/users/{id}/workplaces', [UserController::class, 'addUserIntoWorkplaces'])->middleware(
            'permission:' . PermissionName::UserWorkplaceAdd->value
        );
        Route::delete('/users/{user}/workplaces/{workplace}', [
            UserController::class,
            'deleteUserWorkplace',
        ])->middleware('permission:' . PermissionName::UserWorkplaceRemove->value);

        Route::prefix('bank-accounts')->group(function () {
            Route::get('/', [AdminBankAccountController::class, 'index'])->middleware(
                'permission:' . PermissionName::BankAccountList->value
            );
            Route::get('/{bankAccount}', [AdminBankAccountController::class, 'show'])->middleware(
                'permission:' . PermissionName::BankAccountList->value
            );
            Route::post('/{bankAccount}/approve', [AdminBankAccountController::class, 'approve'])->middleware(
                'permission:' . PermissionName::BankAccountApprove->value
            );
            Route::post('/{bankAccount}/reject', [AdminBankAccountController::class, 'reject'])->middleware(
                'permission:' . PermissionName::BankAccountApprove->value
            );
        });

        Route::get('/users/{user}/papers', [UserController::class, 'getPapers'])->middleware(
            'permission:' . PermissionName::UserPaperList->value
        );
        Route::post('/users/{user}/papers/approve', [UserController::class, 'approvePapers'])->middleware(
            'permission:' . PermissionName::UserPaperApprove->value
        );
        Route::post('/users/{user}/papers/request-completion', [
            UserController::class,
            'requestPaperCompletion',
        ])->middleware('permission:' . PermissionName::UserPaperApprove->value);
        Route::post('/users/{user}/papers/reopen-steps', [UserController::class, 'reopenPaperSteps'])->middleware(
            'permission:' . PermissionName::UserPaperApprove->value
        );
        Route::post('/users/{user}/papers/reopen-emptied-steps', [
            UserController::class,
            'reopenEmptiedPaperSteps',
        ])->middleware('permission:' . PermissionName::UserPaperApprove->value);
        Route::post('/users/{user}/papers/move', [UserController::class, 'movePapers'])->middleware(
            'permission:' . PermissionName::UserPaperApprove->value
        );
        Route::post('/users/{user}/papers/delete', [UserController::class, 'deletePapers'])->middleware(
            'permission:' . PermissionName::UserPaperDelete->value
        );
        Route::post('/users/{user}/papers/restore', [UserController::class, 'restorePapers'])->middleware(
            'permission:' . PermissionName::UserPaperDelete->value
        );
        Route::post('/users/{user}/papers/ocr', [UserController::class, 'extractPapersOcr'])->middleware(
            'permission:' . PermissionName::UserPaperList->value
        );
        Route::post('/users/{user}/papers/ocr/apply', [UserController::class, 'applyPapersOcr'])->middleware(
            'permission:' . PermissionName::UserPaperApprove->value
        );

        /*
         * Admin residence card scan: an admin's own capture, kept apart from the
         * employee's documents. Capture is admin-app only; the web surfaces read.
         */
        Route::post('/users/{user}/paper-uploads', [AdminPaperUploadController::class, 'store'])->middleware(
            'permission:' . PermissionName::UserPaperUpload->value
        );
        Route::get('/paper-uploads/{adminPaperUpload}', [AdminPaperUploadController::class, 'show'])->middleware(
            'permission:' . PermissionName::UserPaperUpload->value
        );
        Route::post('/paper-uploads/{adminPaperUpload}/submit', [
            AdminPaperUploadController::class,
            'submit',
        ])->middleware('permission:' . PermissionName::UserPaperUpload->value);
        Route::delete('/paper-uploads/{adminPaperUpload}', [AdminPaperUploadController::class, 'destroy'])->middleware(
            'permission:' . PermissionName::UserPaperUpload->value
        );

        // The literal segment must be declared before /{user}, or it binds as a user id.
        Route::get('/paper-reconciliation/filter-options', [
            PaperReconciliationController::class,
            'filterOptions',
        ])->middleware('permission:' . PermissionName::UserPaperList->value);
        Route::get('/paper-reconciliation', [PaperReconciliationController::class, 'index'])->middleware(
            'permission:' . PermissionName::UserPaperList->value
        );
        Route::get('/paper-reconciliation/{user}', [PaperReconciliationController::class, 'show'])->middleware(
            'permission:' . PermissionName::UserPaperList->value
        );

        // Japan Post data: both lookups back the user edit form and the employee creation form,
        // so whoever may fill either form may use them
        Route::get('/postal-codes/resolve', [PostalCodeController::class, 'resolve'])->middleware(
            'permission:' . PermissionName::UserEdit->value . '|' . PermissionName::UserCreateEmployee->value
        );
        Route::get('/postal-codes/{code}', [PostalCodeController::class, 'lookup'])->middleware(
            'permission:' . PermissionName::UserEdit->value . '|' . PermissionName::UserCreateEmployee->value
        );

        Route::get('/users/{user}/configs', [UserController::class, 'getUserConfigs'])->middleware(
            'permission:' . PermissionName::UserConfigList->value
        );
        Route::put('/users/{user}/configs', [UserController::class, 'updateUserConfigs'])->middleware(
            'permission:' . PermissionName::UserConfigEdit->value
        );

        Route::get('/groups', [GroupController::class, 'index'])->middleware(
            'permission:' . PermissionName::GroupView->value
        );
        Route::post('/groups', [GroupController::class, 'store'])->middleware(
            'permission:' . PermissionName::GroupEdit->value
        );
        Route::get('/groups/{group}', [GroupController::class, 'show'])->middleware(
            'permission:' . PermissionName::GroupView->value
        );
        Route::patch('/groups/{group}', [GroupController::class, 'update'])->middleware(
            'permission:' . PermissionName::GroupEdit->value
        );
        Route::delete('/groups/{group}', [GroupController::class, 'destroy'])->middleware(
            'permission:' . PermissionName::GroupEdit->value
        );
        Route::post('/groups/{group}/members/{userId}', [GroupController::class, 'attachMember'])->middleware(
            'permission:' . PermissionName::GroupEdit->value
        );
        Route::post('/groups/{group}/members', [GroupController::class, 'batchAttachMembers'])->middleware(
            'permission:' . PermissionName::GroupEdit->value
        );
        Route::delete('/groups/{group}/members/{userId}', [GroupController::class, 'detachMember'])->middleware(
            'permission:' . PermissionName::GroupEdit->value
        );
        Route::get('/users/{user}/groups', [GroupController::class, 'listUserGroups'])->middleware(
            'permission:' . PermissionName::GroupView->value
        );

        Route::prefix('chat')->group(function () {
            Route::get('/direct-conversation', [
                AdminChatConversationController::class,
                'findDirectConversation',
            ])->middleware('permission:' . PermissionName::ChatView->value);
            Route::get('/conversations', [AdminChatConversationController::class, 'index'])->middleware(
                'permission:' . PermissionName::ChatView->value
            );
            Route::post('/conversations', [AdminChatConversationController::class, 'store'])->middleware(
                'permission:' . PermissionName::ChatCreate->value
            );
            Route::get('/conversations/{conversation}', [AdminChatConversationController::class, 'show'])->middleware(
                'permission:' . PermissionName::ChatView->value
            );
            Route::post('/conversations/{conversation}/read', [
                AdminChatConversationController::class,
                'markRead',
            ])->middleware('permission:' . PermissionName::ChatView->value);
            Route::get('/conversations/{conversation}/messages', [
                AdminChatMessageController::class,
                'index',
            ])->middleware('permission:' . PermissionName::ChatView->value);
            Route::get('/conversations/{conversation}/messages/{messageId}/receipts', [
                AdminChatMessageController::class,
                'receipts',
            ])
                ->whereNumber('messageId')
                ->middleware('permission:' . PermissionName::ChatView->value);
            Route::post('/conversations/{conversation}/messages', [
                AdminChatMessageController::class,
                'store',
            ])->middleware(['permission:' . PermissionName::ChatView->value, 'throttle:chat-send-limit']);
            Route::patch('/conversations/{conversation}/title', [
                AdminChatConversationController::class,
                'rename',
            ])->middleware('permission:' . PermissionName::ChatEdit->value);
            Route::patch('/conversations/{conversation}/employee-permissions', [
                AdminChatConversationController::class,
                'updateEmployeePermissions',
            ])->middleware('permission:' . PermissionName::ChatEdit->value);
            // ChatView|ChatEdit: deputies only hold ChatView but must be able to close/reopen
            // group chats. The action gates by participant role: admins still require ChatEdit,
            // while deputies are allowed through with ChatView only. A ChatView-only admin
            // participant (e.g. a creator/support admin without ChatEdit) is rejected at the
            // action level, so the wider middleware is not a security gap.
            Route::post('/conversations/{conversation}/close', [
                AdminChatConversationController::class,
                'close',
            ])->middleware('permission:' . PermissionName::ChatView->value . '|' . PermissionName::ChatEdit->value);
            Route::post('/conversations/{conversation}/reopen', [
                AdminChatConversationController::class,
                'reopen',
            ])->middleware('permission:' . PermissionName::ChatView->value . '|' . PermissionName::ChatEdit->value);
            Route::get('/conversations/{conversation}/participants', [
                AdminChatConversationController::class,
                'listParticipants',
            ])->middleware('permission:' . PermissionName::ChatView->value);
            Route::post('/conversations/{conversation}/participants', [
                AdminChatConversationController::class,
                'addParticipants',
            ])->middleware('permission:' . PermissionName::ChatEdit->value);
            Route::delete('/conversations/{conversation}/participants/{userId}', [
                AdminChatConversationController::class,
                'removeParticipant',
            ])->middleware('permission:' . PermissionName::ChatEdit->value);
            Route::patch('/conversations/{conversation}/participants/{userId}/role', [
                AdminChatConversationController::class,
                'assignParticipantRole',
            ])->middleware('permission:' . PermissionName::ChatEdit->value);
            Route::post('/conversations/{conversation}/pinned-message', [
                AdminChatPinMessageController::class,
                'pin',
            ])->middleware('permission:' . PermissionName::ChatEdit->value);
            Route::delete('/conversations/{conversation}/pinned-message', [
                AdminChatPinMessageController::class,
                'unpin',
            ])->middleware('permission:' . PermissionName::ChatEdit->value);
            Route::delete('/conversations/{conversation}/messages/{messageId}', [
                AdminChatConversationController::class,
                'destroyMessage',
            ])
                ->whereNumber('messageId')
                ->middleware('permission:' . PermissionName::ChatDeleteMessage->value);
            Route::delete('/conversations/{conversation}', [
                AdminChatConversationController::class,
                'destroy',
            ])->middleware('permission:' . PermissionName::ChatDeleteConversation->value);

            Route::get('/support-conversations', [AdminChatSupportController::class, 'index'])->middleware(
                'permission:' . PermissionName::ChatAssign->value
            );
            Route::post('/support-conversations/{conversation}/assign-admins', [
                AdminChatSupportController::class,
                'assign',
            ])->middleware('permission:' . PermissionName::ChatAssign->value);
            Route::post('/support-conversations/{conversation}/remove-admins', [
                AdminChatSupportController::class,
                'remove',
            ])->middleware('permission:' . PermissionName::ChatAssign->value);

            Route::get('/support-types', [AdminChatSupportTypeController::class, 'index'])->middleware(
                'permission:' . PermissionName::ChatAssign->value
            );
            Route::middleware('permission:' . PermissionName::ChatAssign->value)->group(function () {
                Route::post('/support-types/{type}/admins', [AdminChatSupportTypeController::class, 'addAdmin']);
                Route::delete('/support-types/{type}/admins/{userId}', [
                    AdminChatSupportTypeController::class,
                    'removeAdmin',
                ]);
            });

            Route::post('/active', [ChatPresenceController::class, 'active'])->middleware(
                'permission:' . PermissionName::ChatView->value
            );
            Route::delete('/active', [ChatPresenceController::class, 'leave'])->middleware(
                'permission:' . PermissionName::ChatView->value
            );

            Route::get('/banned-keywords', [AdminChatBannedKeywordController::class, 'index'])->middleware(
                'permission:' . PermissionName::ConfigList->value
            );
            Route::post('/banned-keywords', [AdminChatBannedKeywordController::class, 'store'])->middleware(
                'permission:' . PermissionName::ConfigEdit->value
            );
            Route::patch('/banned-keywords/{bannedKeyword}', [
                AdminChatBannedKeywordController::class,
                'update',
            ])->middleware('permission:' . PermissionName::ConfigEdit->value);
            Route::delete('/banned-keywords/{bannedKeyword}', [
                AdminChatBannedKeywordController::class,
                'destroy',
            ])->middleware('permission:' . PermissionName::ConfigEdit->value);
        });

        Route::post('/users/employees/init', [UserController::class, 'initEmployee'])->middleware(
            'permission:' . PermissionName::UserCreateEmployee->value
        );
        Route::post('/users/employees/set-password', [UserController::class, 'setEmployeePassword'])->middleware(
            'permission:' . PermissionName::UserCreateEmployee->value
        );
        Route::post('/users/employees/register', [UserController::class, 'registerEmployee'])->middleware(
            'permission:' . PermissionName::UserCreateEmployee->value
        );

        Route::get('/user-faces', [UserFaceController::class, 'index'])->middleware(
            'permission:' . PermissionName::UserFaceList->value
        );
        Route::post('/user-faces', [UserFaceController::class, 'store'])->middleware(
            'permission:' . PermissionName::UserFaceCreate->value
        );
        Route::put('/user-faces/{userId}', [UserFaceController::class, 'update'])->middleware(
            'permission:' . PermissionName::UserFaceUpdate->value
        );
        Route::delete('/user-faces/{userId}', [UserFaceController::class, 'destroy'])->middleware(
            'permission:' . PermissionName::UserFaceDelete->value
        );

        Route::get('/check-in/spoofed', [AdminCheckInController::class, 'getSpoofedCheckIns'])->middleware(
            'permission:' . PermissionName::CheckInSpoofList->value
        );

        Route::prefix('manage')
            ->middleware('permission:' . PermissionName::ShiftCheckIn->value)
            ->group(function () {
                Route::get('/check-in/shift', [AdminManageCheckInController::class, 'getCheckInShift']);
                Route::post('/check-in/start', [AdminManageCheckInController::class, 'checkIn']);
                Route::post('/check-in/end', [AdminManageCheckInController::class, 'checkOut']);
            });

        // Admin workplace attendance (mobile: self check-in/out + own history).
        // Gated by the same admin auth as web admin login; workplace eligibility is
        // enforced inside the action via team-scoped RBAC. No face capture — just press.
        Route::prefix('attendance')
            ->middleware('permission:' . PermissionName::ManagerAuth->value)
            ->group(function () {
                // Single endpoint for both actions — the backend decides check-in vs check-out
                // from the admin's currently open session at the workplace, so the client never
                // states which one it means.
                Route::post('/check-in-out', [AdminAttendanceCheckInController::class, 'checkInOut'])->middleware(
                    'permission:' . PermissionName::ManagerAttendanceCheckIn->value
                );
                // Read-only preview (nearest workplace + distance) shown before the admin
                // attempts a check-in/check-out, so the mobile UI doesn't have to guess.
                Route::get('/check-in/nearby', [AdminAttendanceCheckInController::class, 'previewNearby'])->middleware(
                    'permission:' . PermissionName::ManagerAttendanceCheckIn->value
                );
                // Read-only: whether the admin currently has an open, valid session at a
                // workplace, independent of calendar-day bucketing (Requirement 1).
                Route::get('/current-session', [AdminAttendanceCheckInController::class, 'currentSession'])->middleware(
                    'permission:' . PermissionName::ManagerAttendanceCheckIn->value
                );
                // Mobile: the acting admin views only their own attendance history.
                Route::get('/my-history', [AdminAttendanceCheckInController::class, 'myHistory']);
                Route::get('/my-history/day', [AdminAttendanceCheckInController::class, 'myDay']);
            });

        // Admin workplace attendance history (web, read-only). GlobalAdmin-only.
        // Level 1: daily summary (one row per admin + day). Level 2: that day's sessions with logs.
        Route::prefix('attendance')
            ->middleware('role:' . RoleName::GlobalAdmin->value)
            ->group(function () {
                Route::get('/daily', [AdminAttendanceHistoryController::class, 'daily']);
                Route::get('/day', [AdminAttendanceHistoryController::class, 'day']);
                Route::get('/export', [AdminAttendanceHistoryController::class, 'export']);
            });

        Route::get('/workplaces', [WorkplaceController::class, 'index'])->middleware(
            'permission:' . PermissionName::WorkplaceList->value
        );
        Route::post('/workplaces', [WorkplaceController::class, 'store'])->middleware(
            'permission:' . PermissionName::WorkplaceCreate->value
        );
        Route::get('/workplaces/{workplace}', [WorkplaceController::class, 'show'])->middleware(
            'permission:' . PermissionName::WorkplaceList->value
        );
        Route::put('/workplaces/{workplace}', [WorkplaceController::class, 'update'])->middleware(
            'permission:' . PermissionName::WorkplaceEdit->value
        );
        Route::delete('/workplaces/{workplace}', [WorkplaceController::class, 'destroy'])->middleware(
            'permission:' . PermissionName::WorkplaceDelete->value
        );

        Route::get('/workplaces/{workplace}/images', [WorkplaceController::class, 'listImages'])->middleware(
            'permission:' . PermissionName::WorkplaceImageList->value
        );
        Route::post('/workplaces/{workplace}/images', [WorkplaceController::class, 'uploadImages'])->middleware(
            'permission:' . PermissionName::WorkplaceImageUpload->value
        );
        Route::delete('/workplaces/{workplace}/images/{image}', [
            WorkplaceController::class,
            'deleteImage',
        ])->middleware('permission:' . PermissionName::WorkplaceImageDelete->value);

        Route::get('/workplaces/{workplace}/positions', [WorkplaceController::class, 'listPositions'])->middleware(
            'permission:' . PermissionName::WorkplacePositionList->value
        );
        Route::post('/workplaces/{workplace}/positions', [WorkplaceController::class, 'storePosition'])->middleware(
            'permission:' . PermissionName::WorkplacePositionCreate->value
        );
        Route::put('/workplaces/{workplace}/positions/reorder', [
            WorkplaceController::class,
            'reorderPositions',
        ])->middleware('permission:' . PermissionName::WorkplacePositionEdit->value);
        Route::put('/workplaces/{workplace}/positions/{position}', [
            WorkplaceController::class,
            'updatePosition',
        ])->middleware('permission:' . PermissionName::WorkplacePositionEdit->value);
        Route::delete('/workplaces/{workplace}/positions/{position}', [
            WorkplaceController::class,
            'deletePosition',
        ])->middleware('permission:' . PermissionName::WorkplacePositionDelete->value);

        Route::get('/workplaces/{workplace}/shifts', [WorkplaceController::class, 'listShifts'])->middleware(
            'permission:' . PermissionName::WorkplaceShiftList->value
        );
        Route::post('/workplaces/{workplace}/shifts', [WorkplaceController::class, 'storeShift'])->middleware(
            'permission:' . PermissionName::WorkplaceShiftCreate->value
        );
        Route::put('/workplaces/{workplace}/shifts/{shift}', [WorkplaceController::class, 'updateShift'])->middleware(
            'permission:' . PermissionName::WorkplaceShiftEdit->value
        );
        Route::delete('/workplaces/{workplace}/shifts/{shift}', [
            WorkplaceController::class,
            'deleteShift',
        ])->middleware('permission:' . PermissionName::WorkplaceShiftDelete->value);

        Route::get('/workplaces/{workplace}/instructions', [
            WorkplaceController::class,
            'listInstructions',
        ])->middleware('permission:' . PermissionName::WorkplaceInstructionList->value);
        Route::post('/workplaces/{workplace}/instructions', [
            WorkplaceController::class,
            'storeInstruction',
        ])->middleware('permission:' . PermissionName::WorkplaceInstructionCreate->value);
        Route::put('/workplaces/{workplace}/instructions/{instruction}', [
            WorkplaceController::class,
            'updateInstruction',
        ])->middleware('permission:' . PermissionName::WorkplaceInstructionEdit->value);
        Route::delete('/workplaces/{workplace}/instructions/{instruction}', [
            WorkplaceController::class,
            'deleteInstruction',
        ])->middleware('permission:' . PermissionName::WorkplaceInstructionDelete->value);

        Route::get('/workplaces/{workplace}/users', [WorkplaceController::class, 'listUsers'])->middleware(
            'permission:' . PermissionName::WorkplaceUserList->value
        );
        Route::post('/workplaces/{workplace}/users', [WorkplaceController::class, 'addUsers'])->middleware(
            'permission:' . PermissionName::WorkplaceUserAdd->value
        );
        Route::delete('/workplaces/{workplace}/users/{user}', [WorkplaceController::class, 'detachUser'])->middleware(
            'permission:' . PermissionName::WorkplaceUserRemove->value
        );

        Route::group(['prefix' => 'workplaces/{workplace}/document-read-configs'], function () {
            Route::get('/', [WorkplaceDocumentReadConfigController::class, 'index'])->middleware(
                'permission:' . PermissionName::WorkplaceEdit->value
            );
            Route::post('/', [WorkplaceDocumentReadConfigController::class, 'store'])->middleware(
                'permission:' . PermissionName::WorkplaceEdit->value
            );
            Route::put('/{config}', [WorkplaceDocumentReadConfigController::class, 'update'])->middleware(
                'permission:' . PermissionName::WorkplaceEdit->value
            );
            Route::delete('/{config}', [WorkplaceDocumentReadConfigController::class, 'destroy'])->middleware(
                'permission:' . PermissionName::WorkplaceEdit->value
            );
            Route::get('/{config}/backfill-count', [
                WorkplaceDocumentReadConfigController::class,
                'backfillCount',
            ])->middleware('permission:' . PermissionName::WorkplaceEdit->value);
            Route::post('/{config}/backfill', [WorkplaceDocumentReadConfigController::class, 'backfill'])->middleware(
                'permission:' . PermissionName::WorkplaceEdit->value
            );
            Route::get('/{config}/stats', [WorkplaceDocumentReadConfigController::class, 'stats'])->middleware(
                'permission:' . PermissionName::WorkplaceEdit->value
            );
        });

        Route::group(['prefix' => 'fixed-schedules'], function () {
            Route::get('/', [FixedScheduleController::class, 'index'])->middleware(
                'permission:' . PermissionName::FixedScheduleList->value
            );
            Route::post('/', [FixedScheduleController::class, 'store'])->middleware(
                'permission:' . PermissionName::FixedScheduleCreate->value
            );
            Route::put('/{schedule}', [FixedScheduleController::class, 'update'])->middleware(
                'permission:' . PermissionName::FixedScheduleEdit->value
            );
            Route::delete('/{schedule}', [FixedScheduleController::class, 'destroy'])->middleware(
                'permission:' . PermissionName::FixedScheduleDelete->value
            );
        });

        Route::group(['prefix' => 'fixed-schedule-change-configs'], function () {
            Route::get('/', [FixedScheduleChangeConfigController::class, 'index'])->middleware(
                'permission:' .
                    PermissionName::FixedScheduleChangeConfigList->value .
                    '|' .
                    PermissionName::FixedScheduleChangeRequestList->value
            );
            Route::put('/bulk', [FixedScheduleChangeConfigController::class, 'bulkUpdate'])->middleware(
                'permission:' . PermissionName::FixedScheduleChangeConfigEdit->value
            );
            Route::put('/{workplace}', [FixedScheduleChangeConfigController::class, 'update'])->middleware(
                'permission:' . PermissionName::FixedScheduleChangeConfigEdit->value
            );
        });

        Route::group(['prefix' => 'fixed-schedule-change-requests'], function () {
            Route::get('/', [FixedScheduleChangeRequestController::class, 'index'])->middleware(
                'permission:' . PermissionName::FixedScheduleChangeRequestList->value
            );
            Route::get('/export', [FixedScheduleChangeRequestController::class, 'export'])->middleware(
                'permission:' . PermissionName::FixedScheduleChangeRequestList->value
            );
            Route::post('/bulk-resolve', [FixedScheduleChangeRequestController::class, 'bulkResolve'])->middleware(
                'permission:' . PermissionName::FixedScheduleChangeRequestProcess->value
            );
            Route::post('/{fixedScheduleChangeRequest}/approve', [
                FixedScheduleChangeRequestController::class,
                'approve',
            ])->middleware('permission:' . PermissionName::FixedScheduleChangeRequestProcess->value);
            Route::post('/{fixedScheduleChangeRequest}/reject', [
                FixedScheduleChangeRequestController::class,
                'reject',
            ])->middleware('permission:' . PermissionName::FixedScheduleChangeRequestProcess->value);
        });

        Route::group(['prefix' => 'admin-leave-requests'], function () {
            // Requester-side: any Admin_User may submit and read their own.
            Route::group(['middleware' => 'permission:' . PermissionName::ManagerAuth->value], function () {
                Route::post('/', [AdminLeaveRequestController::class, 'store']);
                Route::get('/mine', [AdminLeaveRequestController::class, 'mine']);
                Route::post('/preview-days', [AdminLeaveRequestController::class, 'previewDays']);
            });

            // Reviewer-side: only the designated leave managers.
            Route::group(['middleware' => 'permission:' . PermissionName::AdminLeaveManage->value], function () {
                Route::get('/', [AdminLeaveRequestController::class, 'index']);
                Route::post('/{adminLeaveRequest}/approve', [AdminLeaveRequestController::class, 'approve']);
                Route::post('/{adminLeaveRequest}/reject', [AdminLeaveRequestController::class, 'reject']);
            });

            // Declared last so /mine and /preview-days are not swallowed by the wildcard. Either
            // permission gets past the route gate — a reviewer is not necessarily an Admin_User
            // — and the action itself decides whether this particular viewer may read this
            // particular request.
            Route::get('/{adminLeaveRequest}', [AdminLeaveRequestController::class, 'show'])->middleware(
                'permission:' . PermissionName::ManagerAuth->value . '|' . PermissionName::AdminLeaveManage->value
            );
        });

        Route::group(
            [
                'prefix' => 'company-holidays',
                'middleware' => ['permission:' . PermissionName::AdminLeaveManage->value],
            ],
            function () {
                Route::get('/', [CompanyHolidayController::class, 'index']);
                Route::post('/generate', [CompanyHolidayController::class, 'generate']);
                // Declared before the {companyHoliday} route so "settings" is matched as a
                // literal segment rather than bound as a holiday id.
                Route::put('/settings', [CompanyHolidayController::class, 'updateSetting']);
                Route::post('/', [CompanyHolidayController::class, 'store']);
                Route::put('/{companyHoliday}', [CompanyHolidayController::class, 'update']);
                Route::delete('/{companyHoliday}', [CompanyHolidayController::class, 'destroy']);
            }
        );

        Route::get('/configs', [ConfigController::class, 'index'])->middleware(
            'permission:' . PermissionName::ConfigList->value
        );
        Route::put('/configs', [ConfigController::class, 'update'])->middleware(
            'permission:' . PermissionName::ConfigEdit->value
        );

        Route::group(['prefix' => 'document'], function () {
            Route::post('/', [DocumentController::class, 'create'])->middleware(
                'permission:' . PermissionName::DocumentCreate->value
            );
            Route::post('/{document}', [DocumentController::class, 'update'])->middleware(
                'permission:' . PermissionName::DocumentEdit->value
            );
            Route::delete('/{document}', [DocumentController::class, 'delete'])->middleware(
                'permission:' . PermissionName::DocumentDelete->value
            );
            Route::get('/search', [DocumentController::class, 'search'])->middleware(
                'permission:' . PermissionName::DocumentList->value
            );
            Route::get('/', [DocumentController::class, 'index'])->middleware(
                'permission:' . PermissionName::DocumentList->value
            );
            Route::get('/{document}', [DocumentController::class, 'show'])->middleware(
                'permission:' . PermissionName::DocumentList->value
            );
        });

        Route::prefix('exams')->group(function () {
            Route::get('/', [ExamController::class, 'index'])->middleware(
                'permission:' . PermissionName::ExamList->value
            );
            Route::get('/{exam}', [ExamController::class, 'show'])->middleware(
                'permission:' . PermissionName::ExamList->value
            );
            Route::get('/{exam}/statistics', [ExamController::class, 'statistics'])->middleware(
                'permission:' . PermissionName::ExamResultList->value
            );
            Route::post('/', [ExamController::class, 'store'])->middleware(
                'permission:' . PermissionName::ExamCreate->value
            );
            Route::put('/{exam}', [ExamController::class, 'update'])->middleware(
                'permission:' . PermissionName::ExamEdit->value
            );
            Route::delete('/{exam}', [ExamController::class, 'destroy'])->middleware(
                'permission:' . PermissionName::ExamDelete->value
            );
        });

        Route::prefix('exam-results')->group(function () {
            Route::get('/', [ExamResultController::class, 'index'])->middleware(
                'permission:' . PermissionName::ExamResultList->value
            );
            Route::get('/export', [ExamResultController::class, 'export'])->middleware(
                'permission:' . PermissionName::ExamResultExport->value
            );
            Route::get('/{examResult}', [ExamResultController::class, 'show'])->middleware(
                'permission:' . PermissionName::ExamResultList->value
            );
            Route::post('/{examResult}/complete-external-override', [
                ExamResultController::class,
                'completeExternalOverride',
            ])->middleware('permission:' . PermissionName::ExamEdit->value);
        });

        Route::prefix('pdf-templates')->group(function () {
            Route::get('/', [PdfTemplateController::class, 'index'])->middleware(
                'permission:' . PermissionName::PdfTemplateList->value
            );
            Route::post('/', [PdfTemplateController::class, 'store'])->middleware(
                'permission:' . PermissionName::PdfTemplateCreate->value
            );
            Route::get('/data-sources', [PdfTemplateController::class, 'dataSources'])->middleware(
                'permission:' . PermissionName::PdfTemplateList->value
            );
            Route::get('/{template}', [PdfTemplateController::class, 'show'])->middleware(
                'permission:' . PermissionName::PdfTemplateList->value
            );
            Route::get('/{template}/file', [PdfTemplateController::class, 'file'])->middleware(
                'permission:' . PermissionName::PdfTemplateList->value
            );
            Route::put('/{template}', [PdfTemplateController::class, 'update'])->middleware(
                'permission:' . PermissionName::PdfTemplateUpdate->value
            );
            Route::delete('/{template}', [PdfTemplateController::class, 'destroy'])->middleware(
                'permission:' . PermissionName::PdfTemplateDelete->value
            );
            Route::put('/{template}/fields', [PdfTemplateController::class, 'saveFields'])->middleware(
                'permission:' . PermissionName::PdfTemplateUpdate->value
            );
            Route::post('/{template}/re-upload', [PdfTemplateController::class, 'reUpload'])->middleware(
                'permission:' . PermissionName::PdfTemplateUpdate->value
            );
            Route::post('/{template}/lock', [PdfTemplateController::class, 'lock'])->middleware(
                'permission:' . PermissionName::PdfTemplateUpdate->value
            );
            Route::post('/{template}/refresh-lock', [PdfTemplateController::class, 'refreshLock'])->middleware(
                'permission:' . PermissionName::PdfTemplateUpdate->value
            );
            Route::post('/{template}/unlock', [PdfTemplateController::class, 'unlock'])->middleware(
                'permission:' . PermissionName::PdfTemplateUpdate->value
            );
            Route::post('/{template}/preview', [PdfExportController::class, 'preview'])->middleware(
                'permission:' . PermissionName::PdfTemplateExport->value
            );
        });

        Route::prefix('pdf-export')->group(function () {
            Route::post('/batch-export', [PdfExportController::class, 'batchExport'])->middleware(
                'permission:' . PermissionName::PdfTemplateExport->value
            );
            Route::post('/excel/preview', [PdfExportController::class, 'previewExcel'])->middleware(
                'permission:' . PermissionName::PdfTemplateExport->value
            );
            Route::post('/excel/batch-export', [PdfExportController::class, 'batchExportFromExcel'])->middleware(
                'permission:' . PermissionName::PdfTemplateExport->value
            );
            Route::get('/excel/sample', [PdfExportController::class, 'excelSample'])->middleware(
                'permission:' . PermissionName::PdfTemplateExport->value
            );
            Route::get('/excel/limits', [PdfExportController::class, 'excelLimits'])->middleware(
                'permission:' . PermissionName::PdfTemplateExport->value
            );
            Route::get('/batches/{batch}/items', [PdfExportController::class, 'listBatchItems'])->middleware(
                'permission:' . PermissionName::PdfTemplateList->value
            );
            Route::get('/batches/{batch}/download', [PdfExportController::class, 'downloadBatch'])->middleware(
                'permission:' . PermissionName::PdfTemplateExport->value
            );
            Route::get('/items/{item}/download', [PdfExportController::class, 'downloadItem'])->middleware(
                'permission:' . PermissionName::PdfTemplateExport->value
            );
        });

        Route::get('/export-history', [ExportHistoryController::class, 'index'])->middleware(
            'permission:' .
                PermissionName::PdfTemplateList->value .
                '|' .
                PermissionName::UserExportCsv->value .
                '|' .
                PermissionName::WorkHistoryExport->value
        );
        Route::get('/export-history/{batch}', [ExportHistoryController::class, 'show'])->middleware(
            'permission:' .
                PermissionName::PdfTemplateList->value .
                '|' .
                PermissionName::UserExportCsv->value .
                '|' .
                PermissionName::WorkHistoryExport->value
        );
        Route::get('/export-history/{batch}/download', [ExportHistoryController::class, 'download'])->middleware(
            'permission:' . PermissionName::WorkHistoryExport->value
        );
        Route::get('/export-history/{batch}/source-file', [
            ExportHistoryController::class,
            'downloadSourceFile',
        ])->middleware('permission:' . PermissionName::PdfTemplateList->value);

        Route::group(
            ['prefix' => 'import-history', 'middleware' => 'permission:' . PermissionName::ShiftImportOrder->value],
            function () {
                Route::get('/', [ImportHistoryController::class, 'index']);
                Route::get('/{batch}', [ImportHistoryController::class, 'show']);
                Route::get('/{batch}/download', [ImportHistoryController::class, 'download']);
            }
        );

        Route::prefix('csv-export')->group(function () {
            Route::get('/properties', [CsvExportController::class, 'properties'])->middleware(
                'permission:user.csv.export'
            );
            Route::post('/batch', [CsvExportController::class, 'store'])->middleware('permission:user.csv.export');
            Route::get('/batch/{batch}/download', [CsvExportController::class, 'download'])->middleware(
                'permission:user.csv.export'
            );
        });

        Route::prefix('dashboard')
            ->middleware('permission:' . PermissionName::ShiftList->value)
            ->group(function () {
                Route::get('/workplaces', [DashboardController::class, 'workplaces']);
                Route::get('/kpis', [DashboardController::class, 'kpis']);
                Route::get('/capacity', [DashboardController::class, 'capacity']);
                Route::get('/demand-heatmap', [DashboardController::class, 'demandHeatmap']);
                Route::get('/show-up-rate', [DashboardController::class, 'showUpRate']);
                Route::post('/clear-cache', [DashboardController::class, 'clearCache']);
            });

        Route::prefix('forecast')
            ->middleware('permission:' . PermissionName::ShiftList->value)
            ->group(function () {
                Route::get('/options', [ForecastController::class, 'options']);
                Route::post('/', [ForecastController::class, 'generate']);
            });
    });

    Route::prefix('manage')->group(function () {
        Route::get('/auth/check', [AuthController::class, 'authCheckManager'])->middleware(
            'permission:' . PermissionName::ManagerAuth->value
        );

        Route::group(['middleware' => 'permission:' . PermissionName::ManagerProfile->value], function () {
            Route::put('/profile/password', [ProfileController::class, 'changePassword']);
            Route::put('/profile/picture', [ProfileController::class, 'changeProfilePicture']);
            Route::delete('/profile/picture', [ProfileController::class, 'deleteProfilePicture']);
            Route::patch('/profile/info', [ProfileController::class, 'changeInfo']);
            Route::patch('/profile/settings', [ProfileController::class, 'changeSettings']);
        });

        Route::group(['prefix' => 'shifts'], function () {
            Route::get('/users/search', [ShiftController::class, 'searchUser'])->middleware(
                'permission:' . PermissionName::ShiftEdit->value
            );
            Route::group(
                [
                    'prefix' => 'import-order',
                    'middleware' => 'permission:' . PermissionName::ShiftImportOrder->value,
                ],
                function () {
                    Route::post('/preview', [ShiftOrderImportController::class, 'preview']);
                    Route::get('/{batch}', [ShiftOrderImportController::class, 'show']);
                    Route::post('/{batch}/month', [ShiftOrderImportController::class, 'updateMonth']);
                    Route::post('/{batch}/confirm', [ShiftOrderImportController::class, 'confirm']);
                }
            );
            Route::get('/', [ShiftController::class, 'index'])->middleware(
                'permission:' . PermissionName::ShiftList->value
            );
            Route::get('/simple', [ShiftController::class, 'indexSimple'])->middleware(
                'permission:' . PermissionName::ShiftList->value . '|' . PermissionName::WorkHistoryAdd->value
            );
            Route::get('/weekly-work-hours', [ShiftController::class, 'weeklyWorkHours'])->middleware(
                'permission:' . PermissionName::ShiftList->value
            );
            Route::get('/weekly-work-hours/export', [ShiftController::class, 'exportWeeklyWorkHours'])->middleware(
                'permission:' .
                    PermissionName::ShiftList->value .
                    '|' .
                    PermissionName::ShiftExport->value .
                    ',require_all'
            );
            Route::get('/active', [ShiftController::class, 'getActiveShifts'])->middleware(
                'permission:' . PermissionName::ShiftList->value
            );
            Route::post('/', [ShiftController::class, 'create'])->middleware(
                'permission:' . PermissionName::ShiftCreate->value
            );
            Route::get('/{shift}', [ShiftController::class, 'detail'])->middleware(
                'permission:' . PermissionName::ShiftList->value
            );
            Route::put('/{shift}', [ShiftController::class, 'update'])->middleware(
                'permission:' . PermissionName::ShiftEdit->value
            );
            Route::patch('/{shift}/config', [ShiftController::class, 'updateConfig'])->middleware(
                'permission:' . PermissionName::ShiftEdit->value
            );
            Route::get('/{shift}/delete-impact', [ShiftController::class, 'deleteImpact'])->middleware(
                'permission:' . PermissionName::ShiftDelete->value
            );
            Route::delete('/{shift}', [ShiftController::class, 'delete'])->middleware(
                'permission:' . PermissionName::ShiftDelete->value
            );
            Route::get('/{shift}/export', [ShiftController::class, 'exportShift'])->middleware(
                'permission:' . PermissionName::ShiftExport->value
            );
            Route::post('/{shift}/import-csv', [ShiftController::class, 'importCsv'])->middleware(
                'permission:' . PermissionName::ShiftImport->value
            );
            Route::post('/shift-users/{shiftUser}/check-in', [ShiftController::class, 'checkInUser'])->middleware(
                'permission:' . PermissionName::ShiftCheckIn->value
            );
            Route::post('/shift-users/{shiftUser}/check-out', [ShiftController::class, 'checkOutUser'])->middleware(
                'permission:' . PermissionName::ShiftCheckIn->value
            );
            Route::put('/shift-users/{shiftUser}/salary', [ShiftController::class, 'updateSalaryUser'])->middleware(
                'permission:' . PermissionName::ShiftUpdateSalary->value
            );
            Route::put('/shift-users/{shiftUser}/allow-overtime', [
                ShiftController::class,
                'updateAllowOvertime',
            ])->middleware('permission:' . PermissionName::ShiftUpdateOvertime->value);
            Route::post('/{shift}/weekly-work-load', [ShiftController::class, 'getWeeklyWorkLoad'])->middleware(
                'permission:' . PermissionName::ShiftList->value
            );
            Route::get('/{shift}/overtime-eligibility', [ShiftController::class, 'getOvertimeEligibility'])->middleware(
                'permission:' . PermissionName::ShiftUpdateOvertime->value
            );
            Route::put('/{shift}/overtime/bulk', [ShiftController::class, 'bulkUpdateAllowOvertime'])->middleware(
                'permission:' . PermissionName::ShiftUpdateOvertime->value
            );
        });

        // Shift Planner — page gate requires ShiftList + ShiftCreate + ShiftEdit on the
        // workplace; each endpoint enforces its specific permission (actions re-check
        // ShiftCreate/ShiftEdit on the workplace where AND is required).
        Route::group(['prefix' => 'shift-planning'], function () {
            Route::get('/', [ShiftPlanningController::class, 'index'])->middleware(
                'permission:' . PermissionName::ShiftList->value
            );
            Route::put('/draft', [ShiftPlanningController::class, 'saveDraft'])->middleware(
                'permission:' . PermissionName::ShiftEdit->value
            );
            Route::post('/finalize', [ShiftPlanningController::class, 'finalize'])->middleware(
                'permission:' . PermissionName::ShiftEdit->value
            );
            Route::post('/lock', [ShiftPlanningController::class, 'lock'])->middleware(
                'permission:' . PermissionName::ShiftEdit->value
            );
            Route::delete('/lock', [ShiftPlanningController::class, 'unlock'])->middleware(
                'permission:' . PermissionName::ShiftEdit->value
            );
        });

        Route::get('/absent-notices', [AbsentNoticeController::class, 'index'])->middleware(
            'permission:' . PermissionName::ShiftList->value
        );

        Route::patch('/absent-notices/{absentNotice}/status', [
            AbsentNoticeController::class,
            'updateStatus',
        ])->middleware('permission:' . PermissionName::ShiftEdit->value);

        Route::group(['prefix' => 'work-histories'], function () {
            Route::get('/', [WorkHistoryController::class, 'getWorkHistories'])->middleware(
                'permission:' . PermissionName::WorkHistoryList->value
            );
            Route::get('/monthly', [WorkHistoryController::class, 'getMonthlyWorkHistories'])->middleware(
                'permission:' . PermissionName::WorkHistoryList->value
            );
            Route::post('/export', [WorkHistoryController::class, 'exportDetailReport'])->middleware(
                'permission:' . PermissionName::WorkHistoryExport->value
            );
            Route::post('/add', [WorkHistoryController::class, 'addUsersToShift'])->middleware(
                'permission:' . PermissionName::WorkHistoryAdd->value
            );
            Route::patch('/{checkIn}', [WorkHistoryController::class, 'updateCheckIn'])->middleware(
                'permission:' . PermissionName::WorkHistoryEdit->value
            );
            Route::get('/monthly/export', [WorkHistoryController::class, 'exportMonthlyReport'])->middleware(
                'permission:' . PermissionName::WorkHistoryExport->value
            );
        });

        Route::group(
            [
                'prefix' => 'work-history-reconciliations',
                'middleware' => ['permission:' . PermissionName::ReconciliationWork->value],
            ],
            function () {
                Route::get('/', [ReconciliationController::class, 'index']);
                Route::post('/', [ReconciliationController::class, 'store']);
                Route::get('/users', [ReconciliationController::class, 'searchUsers']);
                Route::get('/{reconciliationBatch}', [ReconciliationController::class, 'show']);
                Route::delete('/{reconciliationBatch}', [ReconciliationController::class, 'destroy']);
                Route::patch('/{reconciliationBatch}/rows/{reconciliationRow}', [
                    ReconciliationController::class,
                    'updateRow',
                ]);
                Route::post('/{reconciliationBatch}/bulk-decide', [ReconciliationController::class, 'bulkDecide']);
                Route::post('/{reconciliationBatch}/confirm', [ReconciliationController::class, 'confirm']);
                Route::post('/{reconciliationBatch}/export', [ReconciliationController::class, 'export']);
                Route::get('/{reconciliationBatch}/export', [ReconciliationController::class, 'downloadExport']);
                Route::get('/{reconciliationBatch}/original', [ReconciliationController::class, 'downloadOriginal']);
            }
        );

        Route::group(['prefix' => 'work-history-edit-requests'], function () {
            Route::get('/', [WorkHistoryEditRequestController::class, 'index'])->middleware(
                'permission:' . PermissionName::WorkHistoryEditRequestProcess->value
            );
            Route::post('/bulk-resolve', [WorkHistoryEditRequestController::class, 'bulkResolve'])->middleware(
                'permission:' . PermissionName::WorkHistoryEditRequestProcess->value
            );
            Route::post('/import/preview', [WorkHistoryEditRequestController::class, 'importPreview'])->middleware(
                'permission:' . PermissionName::WorkHistoryEditRequestProcess->value
            );
            Route::post('/import/commit', [WorkHistoryEditRequestController::class, 'importCommit'])->middleware(
                'permission:' . PermissionName::WorkHistoryEditRequestProcess->value
            );
            Route::post('/{workHistoryEditRequest}/approve', [
                WorkHistoryEditRequestController::class,
                'approve',
            ])->middleware('permission:' . PermissionName::WorkHistoryEditRequestProcess->value);
            Route::post('/{workHistoryEditRequest}/reject', [
                WorkHistoryEditRequestController::class,
                'reject',
            ])->middleware('permission:' . PermissionName::WorkHistoryEditRequestProcess->value);
        });

        Route::group(['prefix' => 'reports'], function () {
            Route::get('/', [ShiftReportController::class, 'index'])->middleware(
                'permission:' . PermissionName::WorkReportList->value
            );
            Route::get('/monthly', [ShiftReportController::class, 'getMonthlyReport'])->middleware(
                'permission:' . PermissionName::WorkReportList->value
            );
        });

        Route::group(['prefix' => 'announcements'], function () {
            Route::get('/', [AnnouncementController::class, 'index'])->middleware(
                'permission:' . PermissionName::AnnouncementList->value
            );
            Route::post('/', [AnnouncementController::class, 'store'])->middleware(
                'permission:' . PermissionName::AnnouncementCreate->value
            );
            Route::put('/{announcement}', [AnnouncementController::class, 'update'])->middleware(
                'permission:' . PermissionName::AnnouncementEdit->value
            );
            Route::delete('/{announcement}', [AnnouncementController::class, 'destroy'])->middleware(
                'permission:' . PermissionName::AnnouncementDelete->value
            );
        });

        Route::group(['prefix' => 'notifications'], function () {
            Route::get('/', [NotificationController::class, 'index'])->middleware(
                'permission:' . PermissionName::NotificationList->value
            );
            Route::post('/', [NotificationController::class, 'store'])->middleware(
                'permission:' . PermissionName::NotificationCreate->value
            );
            Route::put('/{notification}', [NotificationController::class, 'update'])->middleware(
                'permission:' . PermissionName::NotificationEdit->value
            );
            Route::delete('/{notification}', [NotificationController::class, 'destroy'])->middleware(
                'permission:' . PermissionName::NotificationDelete->value
            );
        });

        Route::group(['prefix' => 'schedules'], function () {
            Route::get('/{userId}', [ScheduleController::class, 'getUserSchedule'])->middleware(
                'permission:' . PermissionName::UserList->value
            );
            Route::post('/{shiftUserId}/toggle-absent', [ScheduleController::class, 'toggleAbsent'])->middleware(
                'permission:' . PermissionName::UserList->value
            );
        });

        Route::group(['prefix' => 'salary'], function () {
            Route::get('/', [SalaryController::class, 'getSalaries'])->middleware(
                'permission:' . PermissionName::SalaryList->value
            );
            Route::get('/export', [SalaryController::class, 'exportSalary'])->middleware(
                'permission:' . PermissionName::SalaryExport->value
            );
            Route::get('/info', [SalaryController::class, 'getUserSalary'])->middleware(
                'permission:' . PermissionName::SalaryCollect->value
            );
            Route::post('/import-deduction', [SalaryController::class, 'importDeductionCsv'])->middleware(
                'permission:' . PermissionName::SalaryImportDeduction->value
            );
            Route::post('/import-transfer', [SalaryController::class, 'importTransferCsv'])->middleware(
                'permission:' . PermissionName::SalaryImportTransfer->value
            );
            Route::post('/import-remaining', [SalaryController::class, 'importRemainingCsv'])->middleware(
                'permission:' . PermissionName::SalaryImportRemaining->value
            );

            Route::get('/requests', [SalaryController::class, 'getRequests'])->middleware(
                'permission:' . PermissionName::SalaryRequestList->value
            );
            Route::post('/requests', [SalaryController::class, 'createSalaryRequest'])->middleware(
                'permission:' . PermissionName::SalaryRequestCreate->value
            );
            Route::get('/requests/export', [SalaryController::class, 'exportSalaryRequests'])->middleware(
                'permission:' . PermissionName::SalaryRequestExport->value
            );
            Route::get('/requests/{salaryRequest}', [SalaryController::class, 'getDetail'])->middleware(
                'permission:' . PermissionName::SalaryRequestList->value
            );
            Route::post('/requests/{salaryRequest}/approve', [SalaryController::class, 'approve'])->middleware(
                'permission:' .
                    PermissionName::SalaryRequestApprove->value .
                    '|' .
                    PermissionName::SalaryRequestApproveAccountant->value
            );
            Route::post('/requests/{salaryRequest}/reject', [SalaryController::class, 'reject'])->middleware(
                'permission:' . PermissionName::SalaryRequestReject->value
            );
            Route::post('/requests/{salaryRequest}/confirm-paid', [SalaryController::class, 'confirmPaid'])->middleware(
                'permission:' . PermissionName::SalaryRequestConfirmPaid->value
            );

            Route::group(['prefix' => 'processes'], function () {
                Route::get('/', [SalaryController::class, 'getSalaryProcesses'])->middleware(
                    'permission:' . PermissionName::SalaryProcessList->value
                );
                Route::post('/', [SalaryController::class, 'createSalaryProcess'])->middleware(
                    'permission:' . PermissionName::SalaryProcessCreate->value
                );
                Route::put('/{salaryProcess}', [SalaryController::class, 'updateSalaryProcess'])->middleware(
                    'permission:' . PermissionName::SalaryProcessEdit->value
                );
                Route::delete('/{salaryProcess}', [SalaryController::class, 'deleteSalaryProcess'])->middleware(
                    'permission:' . PermissionName::SalaryProcessDelete->value
                );
            });
        });

        Route::group(['prefix' => 'document-read-configs'], function () {
            Route::get('/', [DocumentReadConfigController::class, 'index'])->middleware(
                'permission:' . PermissionName::ConfigList->value
            );
            Route::post('/', [DocumentReadConfigController::class, 'store'])->middleware(
                'permission:' . PermissionName::ConfigEdit->value
            );
            Route::get('/{config}', [DocumentReadConfigController::class, 'show'])->middleware(
                'permission:' . PermissionName::ConfigList->value
            );
            Route::put('/{config}', [DocumentReadConfigController::class, 'update'])->middleware(
                'permission:' . PermissionName::ConfigEdit->value
            );
            Route::delete('/{config}', [DocumentReadConfigController::class, 'destroy'])->middleware(
                'permission:' . PermissionName::ConfigEdit->value
            );
            Route::get('/{config}/confirmations', [DocumentReadConfigController::class, 'confirmations'])->middleware(
                'permission:' . PermissionName::ConfigList->value
            );
            Route::get('/{config}/backfill-count', [DocumentReadConfigController::class, 'backfillCount'])->middleware(
                'permission:' . PermissionName::ConfigEdit->value
            );
            Route::post('/{config}/backfill', [DocumentReadConfigController::class, 'backfill'])->middleware(
                'permission:' . PermissionName::ConfigEdit->value
            );
            Route::get('/{config}/stats', [DocumentReadConfigController::class, 'stats'])->middleware(
                'permission:' . PermissionName::ConfigList->value
            );
        });

        Route::get('/document-read-confirmations', [
            DocumentReadConfigController::class,
            'allConfirmations',
        ])->middleware('permission:' . PermissionName::ConfigList->value);

        Route::prefix('polls')->group(function () {
            Route::get('/', [PollController::class, 'index'])->middleware(
                'permission:' . PermissionName::PollList->value
            );
            Route::post('/', [PollController::class, 'store'])->middleware(
                'permission:' . PermissionName::PollCreate->value
            );
            Route::get('/{poll}', [PollController::class, 'show'])->middleware(
                'permission:' . PermissionName::PollList->value
            );
            Route::put('/{poll}', [PollController::class, 'update'])->middleware(
                'permission:' . PermissionName::PollEdit->value
            );
            Route::delete('/{poll}', [PollController::class, 'destroy'])->middleware(
                'permission:' . PermissionName::PollDelete->value
            );
            Route::post('/{poll}/activate', [PollController::class, 'activate'])->middleware(
                'permission:' . PermissionName::PollEdit->value
            );
            Route::post('/{poll}/deactivate', [PollController::class, 'deactivate'])->middleware(
                'permission:' . PermissionName::PollEdit->value
            );
            Route::get('/{poll}/responses', [PollController::class, 'responses'])->middleware(
                'permission:' . PermissionName::PollList->value
            );
        });

        Route::prefix('chat')->group(function () {
            Route::get('/direct-conversation', [ManageChatController::class, 'directConversation'])->middleware(
                'permission:' . PermissionName::ChatView->value
            );
        });
    });

    Route::prefix('user/papers')->group(function () {
        Route::get('/', [GeneralUserController::class, 'getPapers']);
        Route::post('/', [GeneralUserController::class, 'uploadPapers']);
    });

    Route::prefix('user/document-steps')->group(function () {
        Route::get('/', [DocumentStepController::class, 'overview']);
        Route::get('/{step}', [DocumentStepController::class, 'show'])->whereIn('step', UserPaperStepKey::values());
        Route::post('/{step}/upload', [DocumentStepController::class, 'upload'])->whereIn(
            'step',
            UserPaperStepKey::values()
        );
        // Deprecated: confirming one step at a time cannot enforce the documents a residence
        // status pulls in. Kept for app versions released before submit.
        Route::post('/{step}/confirm', [DocumentStepController::class, 'confirm'])->whereIn(
            'step',
            UserPaperStepKey::values()
        );
        Route::post('/submit', [DocumentStepController::class, 'submit']);
    });

    Route::get('/user/announcements/active', [UserAnnouncementController::class, 'getActive']);

    Route::prefix('user/notifications')->group(function () {
        Route::get('/', [UserNotificationController::class, 'getNotifications']);
        Route::get('/system', [UserNotificationController::class, 'getSystemNotifications']);
        Route::post('/{notification}/read', [UserNotificationController::class, 'readNotification']);
        Route::post('/read-all', [UserNotificationController::class, 'readAllNotifications']);
    });

    // Shared by employee and admin clients — access is membership-based, checked in the controller.
    Route::get('/chat/attachments/{attachment}', [ChatAttachmentController::class, 'download'])->name(
        'chat.attachments.download'
    );
    Route::get('/chat/attachments/{attachment}/thumbnail', [ChatAttachmentController::class, 'thumbnail'])->name(
        'chat.attachments.thumbnail'
    );

    Route::prefix('user/chat')->group(function () {
        Route::get('/conversations', [ChatConversationController::class, 'index']);
        Route::post('/support-conversations', [ChatSupportController::class, 'store'])->middleware(
            'throttle:chat-support-create-limit'
        );
        Route::get('/conversations/{conversation}', [ChatConversationController::class, 'show']);
        Route::post('/conversations/{conversation}/read', [ChatConversationController::class, 'markRead']);
        Route::post('/conversations/{conversation}/close', [ChatConversationController::class, 'close']);
        Route::post('/conversations/{conversation}/reopen', [ChatConversationController::class, 'reopen']);
        Route::get('/conversations/{conversation}/messages', [ChatMessageController::class, 'index']);
        Route::post('/conversations/{conversation}/messages', [ChatMessageController::class, 'store'])->middleware(
            'throttle:chat-send-limit'
        );
        Route::post('/active', [ChatPresenceController::class, 'active']);
        Route::delete('/active', [ChatPresenceController::class, 'leave']);
    });

    Route::prefix('user/profile')->group(function () {
        Route::get('/', [GeneralUserController::class, 'getUserInfo']);
        Route::post('/', [GeneralUserController::class, 'updateUserInfo']);
        Route::delete('/delete', [GeneralUserController::class, 'requestAccountDeletion']);
    });

    Route::prefix('user/salary')->group(function () {
        Route::get('/', [GeneralUserController::class, 'getUserInfo']);
        Route::post('/', [GeneralUserController::class, 'updateUserInfo']);
        Route::delete('/delete', [GeneralUserController::class, 'requestAccountDeletion']);
    });

    Route::post('/user/settings', [GeneralUserController::class, 'updateSettings']);

    Route::middleware([CheckUserPaperStatus::class])
        ->prefix('user')
        ->group(function () {
            Route::post('/password', [GeneralUserController::class, 'updatePassword']);

            Route::prefix('bank-account')->group(function () {
                Route::get('/', [UserBankAccountController::class, 'show']);
                Route::post('/seven-bank', [UserBankAccountController::class, 'chooseSevenBank']);
                Route::post('/images', [UserBankAccountController::class, 'uploadImages']);
                Route::post('/submit', [UserBankAccountController::class, 'submit']);
                Route::delete('/draft', [UserBankAccountController::class, 'discardDraft']);
            });

            Route::get('/my-schedules', [UserScheduleController::class, 'getListUserSchedules']);
            Route::post('/my-schedules/{schedule}/confirmed', [UserScheduleController::class, 'confirmSchedule']);
            Route::get('/my-schedules/{shiftUser}', [UserScheduleController::class, 'getShiftUserDetail']);
            Route::post('/my-schedules/{shiftUser}/absent-notice', [
                UserScheduleController::class,
                'createAbsentNotice',
            ]);
            Route::post('/my-schedules/{shiftUser}/cancel', [UserScheduleController::class, 'cancelShift']);

            Route::get('/shifts/search', [UserScheduleController::class, 'searchShifts']);
            Route::post('/shifts/{shift}/register', [UserScheduleController::class, 'registerShift']);
            Route::get('/shifts/{shift}', [UserScheduleController::class, 'getShiftDetail']);

            Route::get('/workplaces', [UserWorkplaceController::class, 'getWorkplaces']);
            Route::post('/workplaces/register', [UserWorkplaceController::class, 'registerWorkplaces']);
            Route::get('/workplaces/{workplace}/instructions', [UserWorkplaceController::class, 'getInstructions']);

            Route::prefix('fixed-schedule')->group(function () {
                Route::get('/', [UserFixedScheduleController::class, 'show']);
                Route::post('/change-requests', [UserFixedScheduleController::class, 'storeChangeRequest']);
            });

            Route::prefix('check-in')->group(function () {
                Route::get('/shift', [CheckInController::class, 'getCheckInShift']);
                Route::post('/start', [CheckInController::class, 'checkIn']);
                Route::post('/end', [CheckInController::class, 'checkOut']);
                Route::get('/history', [CheckInController::class, 'getHistory']);
                Route::get('/history/{checkInId}', [CheckInController::class, 'getCheckInDetail'])->whereNumber(
                    'checkInId'
                );
                Route::post('/{checkInId}/reason', [CheckInController::class, 'submitAttendanceReason'])->whereNumber(
                    'checkInId'
                );
                Route::get('/reports', [CheckInController::class, 'getReports']);
                Route::get('/reports/{reportId}', [CheckInController::class, 'getReportDetail']);
            });

            Route::prefix('salary')->group(function () {
                Route::get('/info', [UserSalaryController::class, 'getSalaryInfo']);
                Route::get('/requests', [UserSalaryController::class, 'getRequests']);
                Route::post('/requests', [UserSalaryController::class, 'createRequest']);
                Route::post('/requests/{salaryRequest}/cancel', [UserSalaryController::class, 'cancelRequest']);
                Route::post('/requests/{salaryRequest}/confirm-received', [
                    UserSalaryController::class,
                    'confirmReceived',
                ]);
            });

            Route::prefix('exams')->group(function () {
                Route::get('/', [UserExamController::class, 'index']);
                Route::get('/{exam}', [UserExamController::class, 'show']);
                Route::post('/{exam}/start', [UserExamController::class, 'startOrResumeExam']);
                Route::post('/{exam}/submit', [UserExamController::class, 'submitExam']);
                Route::get('/{exam}/result', [UserExamController::class, 'getResult']);
                Route::post('/{exam}/results/{result}/complete-external', [
                    UserExamController::class,
                    'completeExternal',
                ]);
            });

            Route::prefix('documents')->group(function () {
                Route::get('/', [UserDocumentController::class, 'index']);
                Route::get('/{document}', [UserDocumentController::class, 'show']);
            });

            Route::prefix('document-read-configs')->group(function () {
                Route::post('/{config}/confirm', [DocumentReadConfirmationController::class, 'confirm']);
            });

            Route::prefix('polls')->group(function () {
                Route::post('/{poll}/respond', [PollResponseController::class, 'respond']);
            });
        });

    Route::group(['prefix' => 'document'], function () {
        Route::get('/', [DocumentController::class, 'indexForUser']);
        Route::get('{documentId}', [DocumentController::class, 'showForUser']);
    });
});

// Guest support — no auth:api required
Route::post('/guest/chat/support-conversations', [
    App\Http\Controllers\Guest\ChatSupportController::class,
    'store',
])->middleware('throttle:5,1');
Route::post('/guest/chat/conversations/active', [
    App\Http\Controllers\Guest\ChatSupportController::class,
    'checkActive',
])->middleware('throttle:30,1');

Route::middleware('auth.guest_support')->group(function () {
    Route::get('/guest/chat/conversations/{id}', [App\Http\Controllers\Guest\ChatSupportController::class, 'show']);
    Route::get('/guest/chat/conversations/{id}/messages', [
        App\Http\Controllers\Guest\ChatMessageController::class,
        'index',
    ]);
    Route::post('/guest/chat/conversations/{id}/messages', [
        App\Http\Controllers\Guest\ChatMessageController::class,
        'store',
    ])->middleware('throttle:60,1');
    Route::post('/guest/chat/conversations/{id}/reopen', [
        App\Http\Controllers\Guest\ChatLifecycleController::class,
        'reopen',
    ])->middleware('throttle:10,1');
    Route::post('/guest/pusher/auth', App\Http\Controllers\Guest\PusherAuthController::class);
});
