<?php

namespace App\Services\User;

use App\Enums\UserPaperStatus;
use App\Enums\UserPaperType;
use App\Helpers\ImageHelper;
use App\Mail\DocumentUploadCompleteEmail;
use App\Models\User;
use App\Models\UserPaper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class UserService
{
    public function getUserInfo(): array
    {
        // fresh() (not load()) so short_name reflects a nationality change made
        // earlier in this same request/test, since UserInfoShortNameObserver
        // persists it via a separate User instance.
        $user = Auth::user()->fresh('info');
        $user->append(['student_card_expiry_alert', 'residence_card_expiry_alert', 'passport_expiry_alert']);

        return $user->toArray();
    }

    public function getUserPapers(array $conditions): UserPaper
    {
        /** @var User $user */
        $user = Auth::user();
        $userPaper = $user
            ->paper()
            ->with([
                'items' => function ($query) use ($conditions) {
                    $query->where(function ($innerQuery) {
                        $innerQuery->whereNull('deleted_at')->where(function ($folderQuery) {
                            $folderQuery->whereNull('folder')->orWhere('folder', '!=', 'deleted');
                        });
                    });

                    if (isset($conditions['type'])) {
                        return $query->where('type', $conditions['type']);
                    }

                    return $query;
                },
            ])
            ->first();

        if (!$userPaper) {
            $userPaper = new UserPaper();
            $userPaper->items = [];
        }

        $userPaper->status = $user->paper_status;

        return $userPaper;
    }

    public function uploadUserPapers(array $data): array
    {
        /* @var User $user */
        $user = Auth::user();

        return DB::transaction(function () use ($user, $data) {
            $storagePath = 'users/' . $user->id . '/papers';
            /* @var UserPaper $userPaper */
            $userPaper = $user->paper()->firstOrCreate(['user_id' => $user->id]);

            $values = collect($data['files'])->map(function ($file) use ($storagePath, $userPaper, $data) {
                $path = ImageHelper::handleImageUpload($file, $storagePath);

                return [
                    'user_paper_id' => $userPaper->id,
                    'type' => $data['type'],
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                ];
            });

            $userPaper->items()->createMany($values);

            if ($user->paper_status === UserPaperStatus::Approved->value) {
                $user->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);
                Mail::to($user->email)->send(new DocumentUploadCompleteEmail($user));
            } else {
                // Check if all required paper items are uploaded
                $userPaper->load('items');
                $types = $userPaper->items->pluck('type')->unique()->values()->toArray();
                if (empty(array_diff(UserPaperType::getRequiredTypes(), $types))) {
                    $user->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);
                    Mail::to($user->email)->send(new DocumentUploadCompleteEmail($user));
                }
                $userPaper->status = $user->paper_status;
            }

            return [
                'error' => null,
                'data' => $userPaper,
            ];
        });
    }

    public function updateSettings(array $settings): array
    {
        /* @var User $user */
        $user = Auth::user();
        $oldSettings = $user->settings ?? [];
        $user->update(['settings' => array_merge($oldSettings, $settings)]);

        return [
            'error' => null,
            'data' => $user,
        ];
    }
}
