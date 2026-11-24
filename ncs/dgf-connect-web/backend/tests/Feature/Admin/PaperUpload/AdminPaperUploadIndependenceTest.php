<?php

namespace Tests\Feature\Admin\PaperUpload;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsAdminPaperUploadFixtures;
use Tests\TestCase;

/**
 * The whole feature rests on one promise: an admin's scan changes nothing about what the
 * employee filed. This test holds that promise to the tables and to the endpoint the
 * employee app actually reads.
 */
class AdminPaperUploadIndependenceTest extends TestCase
{
    use BuildsAdminPaperUploadFixtures;
    use RefreshDatabase;

    /**
     * Tables the employee's document flow owns, each with the column to order a snapshot
     * by. `user_infos` is keyed by user_id rather than an id of its own.
     *
     * @var array<string, string>
     */
    private const EMPLOYEE_TABLES = [
        'user_papers' => 'id',
        'user_paper_items' => 'id',
        'user_paper_steps' => 'id',
        'user_paper_ocr_results' => 'id',
        'user_infos' => 'user_id',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Queue::fake();
        $this->fakeAdminScanOcr();
    }

    public function testAFullCaptureLifecycleLeavesEveryEmployeeTableByteForByteUnchanged(): void
    {
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        $before = $this->snapshotEmployeeTables();
        $overviewBefore = $this->actingAs($employee, 'api')->getJson('/user/document-steps')->json();

        [, $row] = $this->uploadAndRead($admin, $employee);
        $this->actingAs($admin, 'api')
            ->postJson("/admin/paper-uploads/{$row->id}/submit", $this->confirmPayload($row->refresh()))
            ->assertOk();

        $this->assertEquals($before, $this->snapshotEmployeeTables());

        $overviewAfter = $this->actingAs($employee, 'api')->getJson('/user/document-steps')->json();
        $this->assertEquals(
            $overviewBefore,
            $overviewAfter,
            'the employee app must not be able to tell that an admin scanned them'
        );
    }

    public function testDiscardingADraftAlsoLeavesTheEmployeeSideUntouched(): void
    {
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        $before = $this->snapshotEmployeeTables();

        $uploadId = $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$employee->id}/paper-uploads", [
                'paper_type' => 'foreigner_card_scan',
                'file' => $this->scanImage(),
            ])
            ->json('data.id');
        $this->actingAs($admin, 'api')
            ->deleteJson("/admin/paper-uploads/{$uploadId}")
            ->assertStatus(204);

        $this->assertEquals($before, $this->snapshotEmployeeTables());
    }

    /**
     * @return array<string, array<int, object>>
     */
    private function snapshotEmployeeTables(): array
    {
        $snapshot = [];

        foreach (self::EMPLOYEE_TABLES as $table => $orderBy) {
            $snapshot[$table] = DB::table($table)->orderBy($orderBy)->get()->all();
        }

        $snapshot['users.paper_status'] = DB::table('users')->orderBy('id')->pluck('paper_status', 'id')->all();

        return $snapshot;
    }
}
