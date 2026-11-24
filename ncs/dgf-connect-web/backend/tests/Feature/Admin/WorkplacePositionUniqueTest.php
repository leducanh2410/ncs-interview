<?php

namespace Tests\Feature\Admin;

use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Services\Admin\WorkplaceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkplacePositionUniqueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplaceA;

    private Workplace $workplaceB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();

        foreach (
            [
                PermissionName::WorkplacePositionCreate->value,
                PermissionName::WorkplacePositionEdit->value,
                PermissionName::WorkplacePositionDelete->value,
            ]
            as $name
        ) {
            $permission = Permission::firstOrCreate(['name' => $name], ['display_name' => $name]);
            $this->admin->permissions()->attach($permission);
        }

        $this->workplaceA = Workplace::create([
            'id' => 1,
            'name' => 'Workplace A',
            'address' => 'A',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $this->workplaceB = Workplace::create([
            'id' => 2,
            'name' => 'Workplace B',
            'address' => 'B',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'min_rest_hours' => 1,
        ]);
    }

    public function testSameNameAllowedInDifferentWorkplace(): void
    {
        WorkplacePosition::create(['workplace_id' => $this->workplaceA->id, 'name' => 'Line 1']);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            "/admin/workplaces/{$this->workplaceB->id}/positions",
            ['name' => 'Line 1']
        );

        $response->assertSuccessful();
        $this->assertDatabaseHas('workplace_positions', [
            'workplace_id' => $this->workplaceB->id,
            'name' => 'Line 1',
        ]);
    }

    public function testUpdateToNameUsedByAnotherWorkplaceIsAllowed(): void
    {
        WorkplacePosition::create(['workplace_id' => $this->workplaceA->id, 'name' => 'Line 1']);
        $position = WorkplacePosition::create(['workplace_id' => $this->workplaceB->id, 'name' => 'Line 2']);

        $response = $this->actingAs($this->admin, 'api')->putJson(
            "/admin/workplaces/{$this->workplaceB->id}/positions/{$position->id}",
            ['name' => 'Line 1']
        );

        $response->assertSuccessful();
    }

    public function testUpdateToNameUsedBySamePlaceIsRejected(): void
    {
        WorkplacePosition::create(['workplace_id' => $this->workplaceB->id, 'name' => 'Line 1']);
        $position = WorkplacePosition::create(['workplace_id' => $this->workplaceB->id, 'name' => 'Line 2']);

        $response = $this->actingAs($this->admin, 'api')->putJson(
            "/admin/workplaces/{$this->workplaceB->id}/positions/{$position->id}",
            ['name' => 'Line 1']
        );

        $response->assertStatus(422);
    }

    public function testDuplicateNameRejectedInSameWorkplace(): void
    {
        WorkplacePosition::create(['workplace_id' => $this->workplaceA->id, 'name' => 'Line 1']);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            "/admin/workplaces/{$this->workplaceA->id}/positions",
            ['name' => 'Line 1']
        );

        $response->assertStatus(422);
    }

    public function testNameIsFreedAfterDeletingAPositionThatShiftsStillReference(): void
    {
        $created = $this->actingAs($this->admin, 'api')
            ->postJson("/admin/workplaces/{$this->workplaceA->id}/positions", ['name' => 'Line 1'])
            ->assertSuccessful()
            ->json('data');

        $this->assignPositionToShift($created['id']);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/admin/workplaces/{$this->workplaceA->id}/positions/{$created['id']}")
            ->assertSuccessful();

        // The foreign key forced the fall-back instead of a hard delete.
        $this->assertDatabaseHas('workplace_positions', ['id' => $created['id'], 'is_active' => false]);
        $this->assertEmpty(
            $this->actingAs($this->admin, 'api')
                ->getJson("/admin/workplaces/{$this->workplaceA->id}/positions")
                ->json('data')
        );

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/workplaces/{$this->workplaceA->id}/positions", ['name' => 'Line 1'])
            ->assertSuccessful();
    }

    public function testDeactivatedPositionDoesNotReserveItsName(): void
    {
        WorkplacePosition::create([
            'workplace_id' => $this->workplaceA->id,
            'name' => 'Line 1',
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            "/admin/workplaces/{$this->workplaceA->id}/positions",
            ['name' => 'Line 1']
        );

        $response->assertSuccessful();
    }

    public function testDatabaseRejectsDuplicateActiveNamesInSameWorkplace(): void
    {
        WorkplacePosition::create(['workplace_id' => $this->workplaceA->id, 'name' => 'Line 1']);

        $this->expectException(UniqueConstraintViolationException::class);

        WorkplacePosition::create(['workplace_id' => $this->workplaceA->id, 'name' => 'Line 1']);
    }

    public function testLosingRaceOnUniqueIndexIsReportedAsValidationError(): void
    {
        $service = app(WorkplaceService::class);
        $service->storePosition($this->workplaceA, ['name' => 'Line 1']);

        $this->expectException(ValidationException::class);

        try {
            $service->storePosition($this->workplaceA, ['name' => 'Line 1']);
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame($this->validationMessageForDuplicateName(), $e->errors()['name'] ?? null);

            throw $e;
        }
    }

    public function testLosingRaceOnUpdateIsReportedAsValidationError(): void
    {
        $service = app(WorkplaceService::class);
        $service->storePosition($this->workplaceA, ['name' => 'Line 1']);
        $position = $service->storePosition($this->workplaceA, ['name' => 'Line 2']);

        $this->expectException(ValidationException::class);

        $service->updatePosition($position, ['name' => 'Line 1']);
    }

    public function testViolationOfAnotherUniqueConstraintIsNotSwallowed(): void
    {
        $service = app(WorkplaceService::class);

        $violation = (new UniqueConstraintViolationException(
            'mysql',
            'insert ...',
            [],
            new \RuntimeException('duplicate')
        ))->setIndex('some_other_unique_index');

        $reject = (fn(callable $write) => $this->rejectDuplicateName($write))->bindTo(
            $service,
            WorkplaceService::class
        );

        $this->expectException(UniqueConstraintViolationException::class);

        try {
            $reject(fn() => throw $violation);
        } catch (UniqueConstraintViolationException $e) {
            $this->assertSame('some_other_unique_index', $e->index);

            throw $e;
        }
    }

    /**
     * The driver parses the index name out of the error message and can fail to, leaving it
     * null. That must still reach the user as a validation error rather than a 500.
     */
    public function testUnidentifiedUniqueViolationIsStillReportedAsValidationError(): void
    {
        $service = app(WorkplaceService::class);

        $violation = new UniqueConstraintViolationException(
            'mysql',
            'insert ...',
            [],
            new \RuntimeException('duplicate')
        );
        $this->assertNull($violation->index, 'This test is only meaningful for an unparsed index.');

        $reject = (fn(callable $write) => $this->rejectDuplicateName($write))->bindTo(
            $service,
            WorkplaceService::class
        );

        $this->expectException(ValidationException::class);

        try {
            $reject(fn() => throw $violation);
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status);
            $this->assertArrayHasKey('name', $e->errors());

            throw $e;
        }
    }

    public function testDatabaseAllowsDuplicateNamesAcrossWorkplacesAndInactiveRows(): void
    {
        WorkplacePosition::create(['workplace_id' => $this->workplaceA->id, 'name' => 'Line 1']);
        WorkplacePosition::create(['workplace_id' => $this->workplaceB->id, 'name' => 'Line 1']);
        WorkplacePosition::create([
            'workplace_id' => $this->workplaceA->id,
            'name' => 'Line 1',
            'is_active' => false,
        ]);
        WorkplacePosition::create([
            'workplace_id' => $this->workplaceA->id,
            'name' => 'Line 1',
            'is_active' => false,
        ]);

        $this->assertSame(4, WorkplacePosition::where('name', 'Line 1')->count());
    }

    private function assignPositionToShift(int $positionId): void
    {
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplaceA->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
        ]);

        $shift = Shift::create([
            'workplace_id' => $this->workplaceA->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->admin->id,
            'date' => '2026-07-22',
            'adjusted_date' => '2026-07-22',
            'temp_total' => 1,
            'fixed_total' => 0,
        ]);

        ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $this->admin->id,
            'workplace_position_id' => $positionId,
            'status' => ShiftUserStatus::Approved->value,
        ]);
    }

    /**
     * The message the request layer returns when it catches the duplicate itself, so the
     * race path cannot drift into a differently worded error.
     *
     * @return array<int, string>
     */
    private function validationMessageForDuplicateName(): array
    {
        WorkplacePosition::create(['workplace_id' => $this->workplaceB->id, 'name' => 'Taken']);

        return $this->actingAs($this->admin, 'api')
            ->postJson("/admin/workplaces/{$this->workplaceB->id}/positions", ['name' => 'Taken'])
            ->assertStatus(422)
            ->json('data.name');
    }

    public function testUpdateKeepingOwnNameIsAllowed(): void
    {
        $position = WorkplacePosition::create([
            'workplace_id' => $this->workplaceA->id,
            'name' => 'Line 1',
        ]);

        $response = $this->actingAs($this->admin, 'api')->putJson(
            "/admin/workplaces/{$this->workplaceA->id}/positions/{$position->id}",
            ['name' => 'Line 1']
        );

        $response->assertSuccessful();
    }
}
