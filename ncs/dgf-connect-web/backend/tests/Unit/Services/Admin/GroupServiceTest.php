<?php

namespace Tests\Unit\Services\Admin;

use App\Enums\GroupKind;
use App\Enums\SourceTier;
use App\Exceptions\SystemGroupReadonlyException;
use App\Models\Group;
use App\Services\Admin\GroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class GroupServiceTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    private GroupService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(GroupService::class);
    }

    public function testStoreRejectsSystemGroupKinds(): void
    {
        try {
            $this->service->store([
                'kind' => GroupKind::WorkplaceCurrent->value,
                'name' => 'Should fail',
            ]);
            $this->fail('Expected system group creation to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('kind', $exception->errors());
        }
    }

    public function testUpdateAndDestroyRejectSystemGroups(): void
    {
        $group = $this->createGroup(GroupKind::Worktime, tier: SourceTier::Morning);

        try {
            $this->service->update($group, ['name' => 'Blocked']);
            $this->fail('Expected system group update to be rejected.');
        } catch (SystemGroupReadonlyException $exception) {
            $this->assertSame(['group' => ['System groups are read-only.']], $exception->errors());
        }

        try {
            $this->service->destroy($group);
            $this->fail('Expected system group deletion to be rejected.');
        } catch (SystemGroupReadonlyException $exception) {
            $this->assertSame(['group' => ['System groups are read-only.']], $exception->errors());
        }

        $this->assertDatabaseHas('groups', ['id' => $group->id]);
    }

    public function testStoreRejectsDuplicateAdminGroupNamesAcrossAdminKinds(): void
    {
        $this->createGroup(GroupKind::Manual, 'Shared Name');

        try {
            $this->service->store([
                'kind' => GroupKind::RuleBased->value,
                'name' => 'Shared Name',
                'predicate' => [['field' => 'prefecture', 'values' => ['tokyo']]],
            ]);
            $this->fail('Expected duplicate admin group name to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }
    }

    public function testStoreRejectsPredicateFieldsOutsideWhitelist(): void
    {
        try {
            $this->service->store([
                'kind' => GroupKind::RuleBased->value,
                'name' => 'Invalid Predicate',
                'predicate' => [['field' => 'age', 'values' => ['30']]],
            ]);
            $this->fail('Expected invalid predicate field to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('predicate.0.field', $exception->errors());
        }
    }
}
