<?php

namespace Tests\Feature\Admin;

use App\Enums\ExportBatchStatus;
use App\Enums\ExportBatchType;
use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Models\ExportBatch;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CsvExportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    // ---------------------------------------------------------------
    // Properties endpoint
    // ---------------------------------------------------------------

    public function testPropertiesReturns403WithoutPermission(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/csv-export/properties');

        $response->assertStatus(403);
    }

    public function testPropertiesReturnsRegistry(): void
    {
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/csv-export/properties');

        $response->assertStatus(200);
        $properties = $response->json('data');
        $this->assertIsArray($properties);
        $this->assertGreaterThan(0, count($properties));
        $this->assertArrayHasKey('key', $properties[0]);
        $this->assertArrayHasKey('label_ja', $properties[0]);
    }

    // ---------------------------------------------------------------
    // Store (create CSV export)
    // ---------------------------------------------------------------

    public function testStoreReturns403WithoutPermission(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/admin/csv-export/batch',
            $this->validExportPayload()
        );

        $response->assertStatus(403);
    }

    public function testStoreReturns422WithEmptySelectedKeys(): void
    {
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/csv-export/batch', [
            'selected_keys' => [],
            'ordered_keys' => ['last_name', 'first_name'],
            'select_all' => true,
        ]);

        $response->assertStatus(422);
    }

    public function testStoreReturns422WithUnknownKey(): void
    {
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/csv-export/batch', [
            'selected_keys' => ['not_a_field'],
            'ordered_keys' => ['not_a_field'],
            'select_all' => true,
        ]);

        $response->assertStatus(422);
    }

    public function testStoreReturns422WithBothSelectAllAndUserIds(): void
    {
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);
        $user = User::factory()->create();

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/csv-export/batch', [
            'selected_keys' => ['last_name'],
            'ordered_keys' => ['last_name'],
            'user_ids' => [$user->id],
            'select_all' => true,
        ]);

        $response->assertStatus(422);
    }

    public function testStoreReturns422WithNeitherSelectAllNorUserIds(): void
    {
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/csv-export/batch', [
            'selected_keys' => ['last_name'],
            'ordered_keys' => ['last_name'],
            'user_ids' => [],
            'select_all' => false,
        ]);

        $response->assertStatus(422);
    }

    public function testStoreCreatesBatchAndDispatchesJob(): void
    {
        Queue::fake();
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);
        $user = User::factory()->create(['is_disabled' => false]);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/csv-export/batch', [
            'selected_keys' => ['last_name', 'first_name', 'email'],
            'ordered_keys' => ['last_name', 'first_name', 'email'],
            'user_ids' => [$user->id],
            'select_all' => false,
        ]);

        $response->assertStatus(202);

        $batchId = $response->json('data.id');
        $this->assertNotNull($batchId);

        $this->assertDatabaseHas('export_batches', [
            'id' => $batchId,
            'type' => 'csv',
            'exported_by' => $this->admin->id,
            'status' => 'pending',
        ]);

        $batch = ExportBatch::find($batchId);
        $this->assertGreaterThanOrEqual(1, $batch->csv_total_count);
    }

    public function testStoreReturnsBatchWithMeta(): void
    {
        Queue::fake();
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/admin/csv-export/batch',
            $this->validExportPayload()
        );

        $response->assertStatus(202);
        $data = $response->json('data');
        $this->assertArrayHasKey('meta', $data);
        $this->assertArrayHasKey('export_keys', $data['meta']);
    }

    public function testStoreShowsShortenedExporterNameForSriLankaNationality(): void
    {
        Queue::fake();
        $this->admin->update(['first_name' => 'Ragalage Tharindu Ravishann', 'last_name' => 'Karnasooriya']);
        $this->admin->info->update(['nationality' => Nationality::SriLanka->value]);
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/admin/csv-export/batch',
            $this->validExportPayload()
        );

        $response->assertStatus(202)->assertJsonPath('data.exporter.name', 'THARINDU RAVISHANN');
    }

    // ---------------------------------------------------------------
    // Download
    // ---------------------------------------------------------------

    public function testDownloadReturns403WithoutPermission(): void
    {
        $batch = $this->createCsvBatch($this->admin, [
            'status' => ExportBatchStatus::Completed,
            'file_path' => 'csv-exports/1/test.csv',
        ]);
        Storage::put('csv-exports/1/test.csv', 'csv content');

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/csv-export/batch/{$batch->id}/download");

        $response->assertStatus(403);
    }

    public function testDownloadReturns409WhenNotCompleted(): void
    {
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);
        $batch = $this->createCsvBatch($this->admin, [
            'status' => ExportBatchStatus::Pending,
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/csv-export/batch/{$batch->id}/download");

        $response->assertStatus(404);
    }

    public function testDownloadReturnsSignedUrlWhenCompleted(): void
    {
        Storage::fake('local');
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);
        $batch = $this->createCsvBatch($this->admin, [
            'status' => ExportBatchStatus::Completed,
            'file_path' => 'csv-exports/1/test.csv',
        ]);
        Storage::put('csv-exports/1/test.csv', 'csv content');

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/csv-export/batch/{$batch->id}/download");

        $response->assertStatus(200);
        $this->assertArrayHasKey('download_url', $response->json('data'));
    }

    public function testDownloadReturns404ForPdfBatch(): void
    {
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);
        $pdfBatch = ExportBatch::query()->create([
            'type' => ExportBatchType::Pdf,
            'exported_by' => $this->admin->id,
            'status' => ExportBatchStatus::Completed,
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/csv-export/batch/{$pdfBatch->id}/download");

        $response->assertStatus(404);
    }

    private function grantPermission(User $user, PermissionName $permission): void
    {
        $perm = Permission::firstOrCreate(['name' => $permission->value], ['display_name' => $permission->value]);
        $user->permissions()->syncWithoutDetaching([$perm->id]);
    }

    private function createCsvBatch(User $exporter, array $attrs = []): ExportBatch
    {
        return ExportBatch::query()->create(
            array_merge(
                [
                    'type' => ExportBatchType::Csv,
                    'exported_by' => $exporter->id,
                    'status' => ExportBatchStatus::Pending,
                    'csv_total_count' => 0,
                    'csv_completed_count' => 0,
                    'csv_failed_count' => 0,
                    'meta' => [
                        'selected_keys' => ['last_name', 'first_name'],
                        'ordered_keys' => ['last_name', 'first_name'],
                        'export_keys' => ['last_name', 'first_name'],
                        'user_ids' => [],
                        'select_all' => true,
                        'filters' => ['is_disabled' => false],
                    ],
                ],
                $attrs
            )
        );
    }

    private function validExportPayload(): array
    {
        return [
            'selected_keys' => ['last_name', 'first_name', 'email'],
            'ordered_keys' => ['last_name', 'first_name', 'email'],
            'user_ids' => [],
            'select_all' => true,
            'filters' => ['is_disabled' => '0'],
        ];
    }
}
