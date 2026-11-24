<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ActionResult;
use App\Actions\Admin\Poll\ActivatePollAction;
use App\Actions\Admin\Poll\CreatePollAction;
use App\Actions\Admin\Poll\DeactivatePollAction;
use App\Actions\Admin\Poll\DeletePollAction;
use App\Actions\Admin\Poll\GetPollAction;
use App\Actions\Admin\Poll\GetPollResponsesAction;
use App\Actions\Admin\Poll\GetPollsAction;
use App\Actions\Admin\Poll\UpdatePollAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Poll\StorePollRequest;
use App\Http\Requests\Admin\Poll\UpdatePollRequest;
use App\Models\Poll;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PollController extends Controller
{
    public function index(Request $request, GetPollsAction $action): JsonResponse
    {
        $filters = $request->only(['type', 'workplace_id', 'is_active']);
        $perPage = $request->input('page_size', 15);

        $polls = $action($filters, $perPage);

        return ResponseHelper::successPaginated($polls);
    }

    public function show(Poll $poll, Request $request, GetPollAction $action): JsonResponse
    {
        $validated = $request->validate(['occurrence' => ['nullable', 'integer', 'min:1']]);

        $result = $action($poll->id, isset($validated['occurrence']) ? (int) $validated['occurrence'] : null);

        if ($result->getError()) {
            return ResponseHelper::error($result->getError(), $result->getStatus());
        }

        return ResponseHelper::success($result->getData());
    }

    public function store(StorePollRequest $request, CreatePollAction $action): JsonResponse
    {
        $result = $action($request->validated(), $request->user());

        if ($result->getError()) {
            return ResponseHelper::error($result->getError(), $result->getStatus());
        }

        return ResponseHelper::success($result->getData());
    }

    public function update(Poll $poll, UpdatePollRequest $request, UpdatePollAction $action): JsonResponse
    {
        $result = $action($poll->id, $request->validated());

        if ($result->getError()) {
            return ResponseHelper::error($result->getError(), $result->getStatus());
        }

        return ResponseHelper::success($result->getData());
    }

    public function destroy(Poll $poll, DeletePollAction $action): JsonResponse
    {
        $result = $action($poll->id);

        if ($result->getError()) {
            return ResponseHelper::error($result->getError(), $result->getStatus());
        }

        return ResponseHelper::success();
    }

    public function activate(Poll $poll, ActivatePollAction $action): JsonResponse
    {
        $result = $action($poll->id);

        if ($result->getError()) {
            return ResponseHelper::error($result->getError(), $result->getStatus());
        }

        return ResponseHelper::success($result->getData());
    }

    public function deactivate(Poll $poll, DeactivatePollAction $action): JsonResponse
    {
        $result = $action($poll->id);

        if ($result->getError()) {
            return ResponseHelper::error($result->getError(), $result->getStatus());
        }

        return ResponseHelper::success($result->getData());
    }

    public function responses(Poll $poll, Request $request, GetPollResponsesAction $action): JsonResponse
    {
        $perPage = $request->input('page_size', 15);
        $validated = $request->validate(['occurrence' => ['nullable', 'integer', 'min:1']]);

        $result = $action($poll->id, $perPage, isset($validated['occurrence']) ? (int) $validated['occurrence'] : null);

        if ($result instanceof ActionResult) {
            return ResponseHelper::error($result->getError(), $result->getStatus());
        }

        return ResponseHelper::successPaginated($result);
    }
}
