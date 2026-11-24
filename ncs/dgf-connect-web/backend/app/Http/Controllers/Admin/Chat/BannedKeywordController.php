<?php

namespace App\Http\Controllers\Admin\Chat;

use App\Actions\Chat\Keyword\LoadBannedKeywordsAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Chat\StoreBannedKeywordRequest;
use App\Http\Requests\Admin\Chat\UpdateBannedKeywordRequest;
use App\Models\ChatBannedKeyword;
use Illuminate\Http\JsonResponse;

class BannedKeywordController extends Controller
{
    public function index(): JsonResponse
    {
        $keywords = ChatBannedKeyword::query()->orderBy('id')->get();

        return ResponseHelper::success($keywords);
    }

    public function store(StoreBannedKeywordRequest $request): JsonResponse
    {
        $keyword = ChatBannedKeyword::query()->create($request->validated());

        LoadBannedKeywordsAction::invalidate();

        return ResponseHelper::success($keyword, 201);
    }

    public function update(UpdateBannedKeywordRequest $request, ChatBannedKeyword $bannedKeyword): JsonResponse
    {
        $bannedKeyword->update($request->validated());

        LoadBannedKeywordsAction::invalidate();

        return ResponseHelper::success($bannedKeyword->fresh());
    }

    public function destroy(ChatBannedKeyword $bannedKeyword): JsonResponse
    {
        $bannedKeyword->delete();

        LoadBannedKeywordsAction::invalidate();

        return ResponseHelper::success();
    }
}
