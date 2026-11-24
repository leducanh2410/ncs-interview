<?php

namespace App\Services\Admin;

use App\Actions\Admin\User\RejectUpcomingShiftsAction;
use App\Actions\Admin\User\ReopenEmptiedDocumentStepsAction;
use App\Actions\Admin\User\ReopenOwedDocumentStepsAction;
use App\Actions\Admin\User\ResolveCustomUserConfigsAction;
use App\Actions\Admin\User\ResolveMissingRequiredDocumentsAction;
use App\Actions\Admin\User\ResolveMissingStudentCardExpiryAction;
use App\Actions\Admin\User\ResolveWebAdminAccessAction;
use App\Actions\Chat\Shift\SyncShiftChatParticipantsAction;
use App\Actions\Common\Poll\EvaluateUserAfterStaffNoAssignmentAction;
use App\Actions\Workplace\AssertTestMembershipMatchAction;
use App\Enums\ErrorCode;
use App\Enums\Locale;
use App\Enums\NotificationIcon;
use App\Enums\NotificationType;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Helpers\ImageHelper;
use App\Http\Requests\Admin\User\UpdateRequest;
use App\Http\Requests\Admin\User\UploadImageRequest;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserConfig;
use App\Models\UserDevice;
use App\Models\UserImage;
use App\Models\UserInfo;
use App\Models\UserLockHistory;
use App\Models\UserPaper;
use App\Models\Workplace;
use App\Models\WorkplaceUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class UserService
{
    private EvaluateUserAfterStaffNoAssignmentAction $evaluateUserAfterStaffNoAssignmentAction;

    private AssertTestMembershipMatchAction $assertTestMembershipMatch;

    private RejectUpcomingShiftsAction $rejectUpcomingShifts;

    public function __construct(
        EvaluateUserAfterStaffNoAssignmentAction $evaluateUserAfterStaffNoAssignmentAction,
        AssertTestMembershipMatchAction $assertTestMembershipMatch,
        RejectUpcomingShiftsAction $rejectUpcomingShifts
    ) {
        $this->evaluateUserAfterStaffNoAssignmentAction = $evaluateUserAfterStaffNoAssignmentAction;
        $this->assertTestMembershipMatch = $assertTestMembershipMatch;
        $this->rejectUpcomingShifts = $rejectUpcomingShifts;
    }

    public function listUsers(array $conditions, bool $withCustomConfigs = false): LengthAwarePaginator
    {
        // EloquentFilter strips empty arrays before dispatching, so UserFilter::workplaceIds()
        // never sees "restrict to no workplace" and the search would silently widen into
        // every employee. Answer it here the same way MasterDataService::searchUser() does:
        // an empty restriction matches nobody.
        if (array_key_exists('workplace_ids', $conditions) && $conditions['workplace_ids'] === []) {
            $perPage = (int) ($conditions['page_size'] ?? (new User())->getPerPage());

            return new LengthAwarePaginator([], 0, max($perPage, 1), Paginator::resolveCurrentPage());
        }

        $users = User::getPaginateFiltered(
            $conditions,
            [
                'roles',
                'info',
                'userFaces',
                'workplaces' => function (BelongsToMany $query) {
                    $query->select(['workplaces.id', 'workplaces.name']);
                },
                // Only for the two document flags below; dropped again before serialising
                'paper' => function (HasOne $query) {
                    $query->select(['id', 'user_id', 'document_mismatches']);
                },
            ],
            ['id' => 'asc'],
            $conditions['page_size'] ?? null
        );

        // Which files owe documents, for the whole page at once. Asked here rather than
        // per row because the per-user resolver runs a query for every step it checks.
        $missingByUser = app(ResolveMissingRequiredDocumentsAction::class)->forUsers(collect($users->items()));
        $resolveMissingStudentCardExpiry = app(ResolveMissingStudentCardExpiryAction::class);
        // Both document badges below are an employee's obligation; an admin account is asked
        // for nothing, and the button behind either badge refuses the press
        $webAdmins = app(ResolveWebAdminAccessAction::class)->forUsers(collect($users->items()));

        $resolveCustomConfigs = app(ResolveCustomUserConfigsAction::class);
        $configDefaults = [];
        if ($withCustomConfigs) {
            $users->getCollection()->load('userConfig');
            $configDefaults = $resolveCustomConfigs->defaults();
        }

        $users->through(function ($user) use (
            $missingByUser,
            $resolveMissingStudentCardExpiry,
            $webAdmins,
            $withCustomConfigs,
            $resolveCustomConfigs,
            $configDefaults
        ) {
            $user->roleNames = $user->roles->pluck('display_name')->join(', ');
            $user->append('face_registered');
            $isWebAdmin = isset($webAdmins[$user->id]);
            // Lets the list mark files worth opening without loading every paper
            $user->has_document_mismatch = !$isWebAdmin && !empty($user->paper?->document_mismatches);
            // Only an approved file can be sent back for completion, so only an approved
            // file is worth flagging — the rest are already somewhere in the review cycle.
            // And never an admin account: nothing sends its file back either.
            $user->has_missing_required_documents =
                !$isWebAdmin &&
                $user->paper_status === UserPaperStatus::Approved->value &&
                array_key_exists($user->id, $missingByUser);
            // An approved 留学 employee whose student card carries no expiry date is working
            // today with nothing able to tell whether their enrolment has lapsed. Nothing
            // else surfaces it — their file is complete, so the flag above stays quiet and
            // the papers tab is the only place that knows. Needs no query of its own: the
            // residence status is on the loaded info, the rest is the answer above.
            //
            // Never on an account that signs in to the admin. The roster only reads the date
            // to decide whether to put someone on a shift, and an admin promoted off the floor
            // keeps the 留学 file they filed as an employee — the date says nothing about
            // them any more, and there is nobody to ask for it.
            $user->has_missing_student_card_expiry =
                !$isWebAdmin &&
                $user->paper_status === UserPaperStatus::Approved->value &&
                $user->paper !== null &&
                $resolveMissingStudentCardExpiry($user, $missingByUser[$user->id] ?? []);
            $user->unsetRelation('paper');
            if ($withCustomConfigs) {
                $user->custom_configs = $resolveCustomConfigs($user->userConfig, $configDefaults);
                $user->unsetRelation('userConfig');
            }

            return $user;
        });

        return $users;
    }

    public function getUserDetail(int $id): array
    {
        $user = User::with(['roles', 'info', 'images', 'userFaces'])->findOrFail($id);
        $user->roleNames = $user->roles->pluck('name');
        $user->append([
            'student_card_expiry_alert',
            'residence_card_expiry_alert',
            'passport_expiry_alert',
            'face_registered',
        ]);

        // Lets the detail screen mark the papers tab before it is opened. Same rule the
        // employee list draws: only an approved file is worth pointing at, because that is
        // the only one an admin can send back for completion from there.
        $isApprovedWithAFile = $user->paper_status === UserPaperStatus::Approved->value && $user->paper()->exists();
        $missing = $isApprovedWithAFile ? app(ResolveMissingRequiredDocumentsAction::class)($user) : [];

        // Marks the same tab for the file that owes nothing but a date — and, like the list,
        // neither warning for an account that signs in to the admin: it does not work shifts,
        // so its enrolment is nothing the roster ever reads, and nothing it is missing can be
        // asked for. The admin check sits last, and only of a file that has something to warn
        // about, so the cheaper answers short-circuit the role query away on every other file.
        $studentCardExpiryMissing =
            $isApprovedWithAFile && app(ResolveMissingStudentCardExpiryAction::class)($user, $missing);
        $isWebAdmin = ($missing !== [] || $studentCardExpiryMissing) && app(ResolveWebAdminAccessAction::class)($user);

        $user->has_missing_required_documents = $isApprovedWithAFile && $missing !== [] && !$isWebAdmin;
        $user->has_missing_student_card_expiry = $studentCardExpiryMissing && !$isWebAdmin;

        return $user->toArray();
    }

    public function getUserImages(int $id): Collection
    {
        return UserImage::query()->where('user_id', $id)->get();
    }

    public function getUserDevices(int $id): Collection
    {
        return UserDevice::query()->where('user_id', $id)->get();
    }

    public function updateUserDetail(int $id, UpdateRequest $request): array
    {
        $user = User::query()->findOrFail($id);
        if ($user->paper_status === UserPaperStatus::Registered || $user->paper_status === UserPaperStatus::Pending) {
            return [
                'result' => false,
                'error' => ErrorCode::UserPaperStatusPending,
            ];
        }

        if ($request->has('is_test')) {
            $newIsTest = $request->boolean('is_test');
            if ($newIsTest !== (bool) $user->is_test) {
                $workplaceIds = $user->workplaces()->pluck('workplaces.id')->toArray();
                if (!$this->assertTestMembershipMatch->flagMatchesWorkplaces($newIsTest, $workplaceIds)) {
                    return [
                        'result' => false,
                        'error' => ErrorCode::WorkplaceTestMembershipMismatch,
                    ];
                }
            }
        }

        DB::transaction(function () use ($user, $request, $id) {
            $userData = $request->only([
                'first_name',
                'last_name',
                'first_name_kana',
                'last_name_kana',
                'email',
                'staff_no',
                'is_test',
            ]);
            // An assigned staff number is never cleared from here. The form sends the field on
            // every save, and one opened before the approval that assigned the number still
            // carries it empty — saving it afterwards would silently strip the employee of it.
            if (filled($user->staff_no) && blank($userData['staff_no'] ?? null)) {
                unset($userData['staff_no']);
            }
            $isAssignedStaffNo = !$user->staff_no && !empty($userData['staff_no']);
            $user->update($userData);
            $userInfoData = $request->only([
                'registered_location',
                'birthday',
                'gender',
                'nationality',
                'phone',
                'emergency_contact_phone',
                'postal_code',
                'prefecture',
                'address',
                'japanese_level',
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
                'is_household_head',
                'facebook_link',
                'line_link',
            ]);
            $userInfoData['is_household_head'] ??= true;
            $userInfo = UserInfo::query()->updateOrCreate(['user_id' => $id], $userInfoData);
            // UserInfoShortNameObserver persists short_name via a separate User
            // instance; refresh so $user (returned below) reflects it, before
            // attaching the already-fetched info relation.
            $user->refresh();
            $user->setRelation('info', $userInfo);

            if ($isAssignedStaffNo) {
                $notification = Notification::query()->create([
                    'type' => NotificationType::STAFF_NO_ASSIGNED->value,
                    'title' => __('lang.staff_no_assigned.title'),
                    'content' => __('lang.staff_no_assigned.content'),
                    'icon' => NotificationIcon::INFO->value,
                    'scheduled_at' => now(),
                    'user_id' => $id,
                    'data' => [
                        'staff_no' => $userData['staff_no'],
                    ],
                ]);
                $notification->translations()->createMany(
                    array_map(
                        fn($lang) => [
                            'lang' => $lang,
                            'title' => __('lang.staff_no_assigned.title', locale: $lang),
                            'content' => __('lang.staff_no_assigned.content', locale: $lang),
                        ],
                        Locale::getTranslationLocales()
                    )
                );

                // Evaluate pending polls for the user after first staff_no assignment
                $this->evaluateUserAfterStaffNoAssignmentAction->__invoke($user);
            }
        });

        return [
            'result' => true,
            'user' => $user,
        ];
    }

    public function uploadUserImage(int $id, UploadImageRequest $request): string
    {
        $user = User::query()->findOrFail($id);
        $path = ImageHelper::handleImageUpload($request->file('file'), 'users/' . $id, 400);
        $user->images()->create(['path' => $path]);

        return $path;
    }

    public function deleteUserImage(int $userId, int $imageId): void
    {
        $user = User::query()->findOrFail($userId);
        $image = UserImage::query()->where('user_id', $userId)->findOrFail($imageId);

        DB::transaction(function () use ($user, $image) {
            if ($user->profile_picture === $image->path) {
                $user->update(['profile_picture' => null]);
            }
            $image->delete();
        });
        Storage::delete($image->path);
    }

    public function deleteUserDevice(int $userId, int $deviceId): void
    {
        UserDevice::query()->where('user_id', $userId)->where('id', $deviceId)->delete();
    }

    public function getUserWorkplaces(int $userId): Collection
    {
        return Workplace::query()
            ->join('workplace_users', 'workplaces.id', '=', 'workplace_users.workplace_id')
            ->where('workplace_users.user_id', $userId)
            ->select('workplaces.*')
            ->get();
    }

    public function addUserIntoWorkplaces(int $userId, array $workplaceIds): ?ErrorCode
    {
        if (!$this->assertTestMembershipMatch->__invoke([$userId], $workplaceIds)) {
            return ErrorCode::WorkplaceTestMembershipMismatch;
        }

        DB::transaction(function () use ($userId, $workplaceIds) {
            foreach ($workplaceIds as $workplaceId) {
                WorkplaceUser::query()->firstOrCreate([
                    'user_id' => $userId,
                    'workplace_id' => $workplaceId,
                ]);
            }
        });

        return null;
    }

    public function disableUser(int $userId, array $params): array
    {
        $authUser = Auth::user();
        if ($authUser->id === $userId) {
            return [
                'result' => false,
                'error' => ErrorCode::UserCannotDisableSelf,
            ];
        }

        $user = User::query()->findOrFail($userId);
        if ($user->hasRole(RoleName::GlobalAdmin)) {
            return [
                'result' => false,
                'error' => ErrorCode::UserCannotDisableGlobalAdmin,
            ];
        }

        return SyncShiftChatParticipantsAction::batch(
            fn() => DB::transaction(function () use ($userId, $params, $authUser) {
                $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
                if ($user->is_disabled) {
                    return [
                        'result' => false,
                        'error' => ErrorCode::UserAlreadyDisabled,
                    ];
                }

                $user->update(['is_disabled' => true]);
                UserLockHistory::query()->create([
                    'user_id' => $userId,
                    'is_locked' => true,
                    'reason' => $params['reason'],
                    'action_by' => $authUser->id,
                ]);

                ($this->rejectUpcomingShifts)($user, $authUser->id);

                return [
                    'result' => true,
                ];
            })
        );
    }

    public function enableUser(int $userId, array $params): array
    {
        return DB::transaction(function () use ($userId, $params) {
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            if (User::originalEmailFromDeleted($user->email) !== null) {
                return [
                    'result' => false,
                    'error' => ErrorCode::UserCannotEnableDeleted,
                ];
            }

            if (!$user->is_disabled) {
                return [
                    'result' => false,
                    'error' => ErrorCode::UserAlreadyEnabled,
                ];
            }

            $user->update(['is_disabled' => false]);
            UserLockHistory::query()->create([
                'user_id' => $userId,
                'is_locked' => false,
                'reason' => $params['reason'],
                'action_by' => Auth::id(),
            ]);

            return [
                'result' => true,
            ];
        });
    }

    public function getLockHistories(User $user): Collection
    {
        $histories = UserLockHistory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('action_at')
            ->orderByDesc('id')
            ->get()
            ->load(['actionBy']);

        $histories->each(
            fn(UserLockHistory $history) => $history->actionBy?->setVisible([
                'id',
                'name',
                'staff_no',
                'profile_picture',
            ])
        );

        return $histories;
    }

    public function assignRoles(int $userId, array $roleNames): array
    {
        if (Auth::id() === $userId) {
            return [
                'result' => false,
                'error' => ErrorCode::UserCannotAssignRoleSelf,
            ];
        }

        $user = User::query()->findOrFail($userId);
        if ($user->hasRole(RoleName::GlobalAdmin) && Auth::id() !== 1) {
            return [
                'result' => false,
                'error' => ErrorCode::UserCannotAssignRoleGlobalAdmin,
            ];
        }

        $user->syncRoles($roleNames);

        return [
            'result' => true,
        ];
    }

    public function getPapers(User $user): ?UserPaper
    {
        $userPaper = $user->paper;
        if (!$userPaper) {
            $userPaper = $user->paper()->create();
        }
        $userPaper->load([
            'items' => function (HasMany $hasMany) {
                $hasMany->orderBy('type');
            },
        ]);
        $userPaper->status = $user->paper_status;
        // Documents the current residence status calls for but the file does not have.
        // Approved-but-incomplete employees keep their approval; the admin is told instead.
        $missing = app(ResolveMissingRequiredDocumentsAction::class)($user);
        // Told on the tab itself, not only when the approve modal refuses the submit: on an
        // approved file nothing else ever raises it, and on one under review the admin
        // should know before working through the documents.
        $studentCardExpiryMissing = app(ResolveMissingStudentCardExpiryAction::class)($user, $missing);

        // Neither warning for an account that signs in to the admin. The list and the detail
        // screen already stay quiet for one, and the tab is where both warnings carry the
        // button that acts on them — leaving them here would have moved the badge one tab
        // deeper instead of removing it, and RequestDocumentCompletionAction refuses the
        // press for either reason, so the admin would be pressing a button that can only
        // answer 422. Asked last, and only of a file that has something to warn about, so
        // the role query is skipped on every file that was never going to — a recorded
        // mismatch counts as something to warn about, because the flag below is what lets
        // the tab drop its standing mismatch warning.
        $isWebAdmin =
            ($missing !== [] || $studentCardExpiryMissing || !empty($userPaper->document_mismatches)) &&
            app(ResolveWebAdminAccessAction::class)($user);

        $userPaper->missing_required_steps = $isWebAdmin ? [] : $missing;
        $userPaper->student_card_expiry_missing = $studentCardExpiryMissing && !$isWebAdmin;
        $userPaper->reopenable_steps =
            !$isWebAdmin && $user->paper_status === UserPaperStatus::Pending->value
                ? app(ReopenOwedDocumentStepsAction::class)->stepsStuckOnImagesOnFile($user, $missing)
                : [];
        $emptiedSteps = app(ReopenEmptiedDocumentStepsAction::class)->resolve($user);
        $emptiedStepKeys = [...$emptiedSteps['reopen'], ...$emptiedSteps['clear']];
        $userPaper->emptied_steps =
            $emptiedStepKeys !== [] && !$isWebAdmin && !app(ResolveWebAdminAccessAction::class)($user)
                ? $emptiedStepKeys
                : [];
        // document_mismatches is left exactly as the file has it, admin account or not. It
        // is not something the account owes anyone; it is what the uploaded documents say
        // about each other, and the approve modal warns with it while the admin decides. A
        // promotion touches neither paper_status nor the items, so a submission caught
        // mid-review stays reviewable — silencing it here would take the warning out of the
        // one screen where it is not a reminder but part of the decision being made. The
        // flag is what lets the tab drop its standing warning while the modal keeps its own.
        $userPaper->is_web_admin = $isWebAdmin;
        // status, missing_required_steps, student_card_expiry_missing and is_web_admin dress
        // up the response; none of them is a column on user_papers, so setting them leaves
        // the model dirty with attributes no update can write. Re-syncing makes a save()
        // added here later a no-op instead of a failed write, and keeps display-only values
        // off the row.
        $userPaper->syncOriginal();

        return $userPaper;
    }

    public function getUserConfigs(int $userId)
    {
        return UserConfig::query()->where('user_id', $userId)->first();
    }

    public function updateUserConfigs(int $userId, array $params)
    {
        return UserConfig::query()->updateOrCreate(['user_id' => $userId], $params);
    }
}
