<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ErrorCode;
use App\Enums\GroupKind;
use App\Exceptions\RuleBasedGroupTooBroadException;
use App\Exceptions\SystemGroupReadonlyException;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Group\AttachMemberRequest;
use App\Http\Requests\Admin\Group\BatchAttachMembersRequest;
use App\Http\Requests\Admin\Group\IndexRequest;
use App\Http\Requests\Admin\Group\StoreManualRequest;
use App\Http\Requests\Admin\Group\StoreRuleBasedRequest;
use App\Http\Requests\Admin\Group\UpdateRequest;
use App\Models\Group;
use App\Models\User;
use App\Services\Admin\GroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class GroupController extends Controller
{
    public function __construct(private readonly GroupService $groupService) {}

    public function index(IndexRequest $request): JsonResponse
    {
        return ResponseHelper::successPaginated($this->groupService->list($request->validated()));
    }

    public function show(Group $group, Request $request): JsonResponse
    {
        $params = Validator::validate($request->all(), [
            'members_per_page' => ['nullable', 'integer', 'min:1'],
            'members_page' => ['nullable', 'integer', 'min:1'],
        ]);

        return ResponseHelper::success(
            $this->groupService->show($group, $params['members_per_page'] ?? null, $params['members_page'] ?? null)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateStorePayload($request);

        try {
            $group = DB::transaction(fn() => $this->groupService->store($validated));
        } catch (RuleBasedGroupTooBroadException $exception) {
            return ResponseHelper::unprocessableEntity(ErrorCode::UnprocessableEntity, $exception->errors());
        }

        return ResponseHelper::success($group, 201);
    }

    public function update(Group $group, UpdateRequest $request): JsonResponse
    {
        try {
            $updatedGroup = DB::transaction(fn() => $this->groupService->update($group, $request->validated()));
        } catch (SystemGroupReadonlyException | RuleBasedGroupTooBroadException $exception) {
            return ResponseHelper::unprocessableEntity(ErrorCode::UnprocessableEntity, $exception->errors());
        }

        return ResponseHelper::success($updatedGroup);
    }

    public function destroy(Group $group): JsonResponse
    {
        try {
            DB::transaction(fn() => $this->groupService->destroy($group));
        } catch (SystemGroupReadonlyException $exception) {
            return ResponseHelper::unprocessableEntity(ErrorCode::UnprocessableEntity, $exception->errors());
        }

        return ResponseHelper::success();
    }

    public function attachMember(Group $group, AttachMemberRequest $request): JsonResponse
    {
        DB::transaction(fn() => $this->groupService->attachMember($group, $request->validated()['user_id']));

        return ResponseHelper::success();
    }

    public function batchAttachMembers(Group $group, BatchAttachMembersRequest $request): JsonResponse
    {
        DB::transaction(fn() => $this->groupService->batchAttachMembers($group, $request->validated()['user_ids']));

        return ResponseHelper::success();
    }

    public function listUserGroups(User $user): JsonResponse
    {
        return ResponseHelper::success($this->groupService->listUserGroups($user));
    }

    public function detachMember(Group $group, int $userId): JsonResponse
    {
        DB::transaction(fn() => $this->groupService->detachMember($group, $userId));

        return ResponseHelper::success();
    }

    private function validateStorePayload(Request $request): array
    {
        $payload = $this->normalizePayload($request->all());

        return match ($payload['kind'] ?? null) {
            GroupKind::Manual->value => Validator::make(
                $payload,
                (new StoreManualRequest())->rules(),
                (new StoreManualRequest())->messages()
            )->validate(),
            GroupKind::RuleBased->value => Validator::make(
                $payload,
                (new StoreRuleBasedRequest())->rules(),
                (new StoreRuleBasedRequest())->messages()
            )->validate(),
            default => Validator::make($payload, [
                'kind' => [
                    'bail',
                    'required',
                    'string',
                    Rule::in([GroupKind::Manual->value, GroupKind::RuleBased->value]),
                ],
            ])->validate(),
        };
    }

    private function normalizePayload(array $payload): array
    {
        if (isset($payload['name']) && is_string($payload['name'])) {
            $payload['name'] = mb_trim($payload['name']);
        }

        if (!isset($payload['predicate']) || !is_array($payload['predicate'])) {
            return $payload;
        }

        $payload['predicate'] = array_map(function ($clause) {
            if (!is_array($clause)) {
                return $clause;
            }

            $field =
                isset($clause['field']) && is_string($clause['field']) ? mb_trim($clause['field']) : $clause['field'];

            $values = $this->normalizeClauseValues($clause, $field);

            return [
                'field' => $field,
                'values' => $values,
            ];
        }, $payload['predicate']);

        return $payload;
    }

    private function normalizeClauseValues(array $clause, ?string $field): array
    {
        if (isset($clause['values']) && is_array($clause['values'])) {
            return array_values(
                array_filter(
                    array_map(function ($value) use ($field) {
                        if (!is_string($value)) {
                            return null;
                        }

                        $value = mb_trim($value);
                        if ($field === 'prefecture') {
                            $value = mb_strtolower($value);
                        }

                        return $value === '' ? null : $value;
                    }, $clause['values'])
                )
            );
        }

        if (isset($clause['value']) && is_string($clause['value'])) {
            $value = mb_trim($clause['value']);
            if ($field === 'prefecture') {
                $value = mb_strtolower($value);
            }

            return $value === '' ? [] : [$value];
        }

        return [];
    }
}
