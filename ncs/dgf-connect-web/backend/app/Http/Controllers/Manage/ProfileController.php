<?php

namespace App\Http\Controllers\Manage;

use App\Actions\Common\ChangePasswordAction;
use App\Actions\Common\UpdateUserAction;
use App\Actions\Manage\Profile\ChangeProfilePictureAction;
use App\Actions\Manage\Profile\ChangeSettingAction;
use App\Actions\Manage\Profile\DeleteProfilePictureAction;
use App\Enums\Locale;
use App\Helpers\ResponseHelper;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProfileController
{
    public function changePassword(Request $request, ChangePasswordAction $action): JsonResponse
    {
        $data = Validator::validate($request->all(), [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'min:6', 'confirmed'],
        ]);

        return $action($data['password'])->toResponse();
    }

    public function changeProfilePicture(Request $request, ChangeProfilePictureAction $action): JsonResponse
    {
        $data = Validator::validate($request->all(), [
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,bmp,webp,heic', 'max:8192'],
        ]);

        return $action($data['image'])->toResponse();
    }

    public function deleteProfilePicture(DeleteProfilePictureAction $action): JsonResponse
    {
        return $action()->toResponse();
    }

    public function changeInfo(Request $request, UpdateUserAction $action): JsonResponse
    {
        $data = Validator::validate($request->all(), [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name_kana' => ['required', 'string', 'max:255'],
            'last_name_kana' => ['required', 'string', 'max:255'],
        ]);
        /** @var User $user */
        $user = Auth::user();
        $result = $action($user, $data);

        if ($result->getError()) {
            return $result->toResponse();
        }

        return ResponseHelper::success($result->getData());
    }

    public function changeSettings(Request $request, ChangeSettingAction $action): JsonResponse
    {
        $data = Validator::validate($request->all(), [
            'lang' => ['required', 'string', Rule::in(Locale::values())],
        ]);

        return $action($data)->toResponse();
    }
}
