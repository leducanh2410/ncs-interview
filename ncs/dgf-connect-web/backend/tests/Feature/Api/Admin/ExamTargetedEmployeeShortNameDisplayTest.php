<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\Locale;
use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamTargetedEmployeeShortNameDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplaceA;

    private Workplace $workplaceB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (
            [PermissionName::ExamList->value, PermissionName::ExamCreate->value, PermissionName::ExamEdit->value]
            as $permissionName
        ) {
            Permission::firstOrCreate(['name' => $permissionName], ['display_name' => $permissionName]);
        }

        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $role->permissions()->syncWithoutDetaching(
            Permission::query()
                ->whereIn('name', [
                    PermissionName::ExamList->value,
                    PermissionName::ExamCreate->value,
                    PermissionName::ExamEdit->value,
                ])
                ->pluck('id')
                ->all()
        );

        $this->admin = User::factory()->create();
        $this->admin->addRole($role);

        $this->workplaceA = Workplace::create([
            'name' => 'A',
            'address' => '1-1-1',
            'latitude' => '35.0',
            'longitude' => '139.0',
        ]);
        $this->workplaceB = Workplace::create([
            'name' => 'B',
            'address' => '1-1-1',
            'latitude' => '35.0',
            'longitude' => '139.0',
        ]);
    }

    public function testShowReturnsShortenedNameForSriLankaTargetedEmployee(): void
    {
        $named = $this->makeSriLankaMember($this->workplaceA);

        $examId = $this->actingAs($this->admin, 'api')
            ->postJson(
                '/admin/exams',
                $this->examPayload([
                    'workplaces' => [$this->workplaceA->id],
                    'targeted_users' => [$named->id],
                ])
            )
            ->json('data.id');

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/exams/{$examId}");

        $response->assertStatus(200);
        $targeted = collect($response->json('data.targeted_users'))->keyBy('id');
        $this->assertEquals('THARINDU RAVISHANN', $targeted[$named->id]['name']);
        $this->assertArrayNotHasKey('short_name', $targeted[$named->id]);
    }

    public function testValidationErrorNamesOffendingSriLankaEmployeeWithShortenedName(): void
    {
        $outsider = $this->makeSriLankaMember($this->workplaceB);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/admin/exams',
            $this->examPayload([
                'workplaces' => [$this->workplaceA->id],
                'targeted_users' => [$outsider->id],
            ])
        );

        $response->assertStatus(422);
        $error = json_encode($response->json('data.targeted_users'), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('THARINDU RAVISHANN', $error);
        $this->assertStringNotContainsString('KARNASOORIYA RAGALAGE', $error);
    }

    private function makeSriLankaMember(Workplace $workplace): User
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);
        $user->workplaces()->attach($workplace->id);

        return $user;
    }

    private function examPayload(array $overrides = []): array
    {
        return array_merge(
            [
                'title' => array_fill_keys(Locale::values(), 'Targeted exam'),
                'languages' => Locale::values(),
                'is_required' => false,
                'retry_limit' => 1,
                'min_score' => 1,
                'time_limit' => 30,
                'workplaces' => [],
                'documents' => [],
                'questions' => [
                    [
                        'id' => null,
                        'content' => array_fill_keys(Locale::values(), 'Q1?'),
                        'options' => [
                            ['id' => null, 'content' => array_fill_keys(Locale::values(), 'A'), 'is_correct' => true],
                            ['id' => null, 'content' => array_fill_keys(Locale::values(), 'B'), 'is_correct' => false],
                        ],
                    ],
                ],
            ],
            $overrides
        );
    }
}
