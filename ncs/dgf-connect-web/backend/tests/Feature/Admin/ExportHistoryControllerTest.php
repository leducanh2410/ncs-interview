<?php

namespace Tests\Feature\Admin;

use App\Enums\ExportBatchStatus;
use App\Enums\ExportBatchType;
use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Models\ExportBatch;
use App\Models\PdfTemplate;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportHistoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    public function testReturns403WithoutAnyPermission(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/export-history');

        $response->assertStatus(403);
    }

    public function testReturnsBothPdfAndCsvRowsWhenNoTypeFilterAndBothPermissions(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);

        $this->createPdfBatch($this->admin);
        $this->createCsvBatch($this->admin);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/export-history');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('data.total'));
    }

    public function testReturnsOnlyPdfRowsForPdfOnlyPermission(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);

        $this->createPdfBatch($this->admin);
        $this->createCsvBatch($this->admin);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/export-history');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals('pdf', $response->json('data.data.0.type'));
    }

    public function testReturnsOnlyCsvRowsForCsvOnlyPermission(): void
    {
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);

        $this->createPdfBatch($this->admin);
        $this->createCsvBatch($this->admin);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/export-history');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals('csv', $response->json('data.data.0.type'));
    }

    public function testFiltersByTypePdf(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);

        $this->createPdfBatch($this->admin);
        $this->createCsvBatch($this->admin);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/export-history?type=pdf');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals('pdf', $response->json('data.data.0.type'));
    }

    public function testFiltersByTypeCsv(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);

        $this->createPdfBatch($this->admin);
        $this->createCsvBatch($this->admin);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/export-history?type=csv');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals('csv', $response->json('data.data.0.type'));
    }

    public function testCsvRowIncludesTypeFilePathAndMeta(): void
    {
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);

        $batch = $this->createCsvBatch($this->admin);
        $batch->update([
            'file_path' => 'csv-exports/1/test.csv',
            'meta' => ['selected_keys' => ['last_name', 'first_name']],
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/export-history');

        $response->assertStatus(200);
        $data = $response->json('data.data.0');
        $this->assertEquals('csv', $data['type']);
        $this->assertEquals('csv-exports/1/test.csv', $data['file_path']);
        $this->assertEquals(['last_name', 'first_name'], $data['meta']['selected_keys']);
    }

    public function testShowReturnsPdfBatchForPdfPermission(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);

        $batch = $this->createPdfBatch($this->admin);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/export-history/{$batch->id}");

        $response->assertStatus(200);
        $this->assertEquals('pdf', $response->json('data.type'));
        $this->assertEquals('Test Template', $response->json('data.pdf_template.name'));
    }

    public function testShowReturnsCsvBatchForCsvPermission(): void
    {
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);

        $batch = $this->createCsvBatch($this->admin);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/export-history/{$batch->id}");

        $response->assertStatus(200);
        $this->assertEquals('csv', $response->json('data.type'));
        $this->assertEquals(10, $response->json('data.total_count'));
    }

    public function testIndexShowsShortenedExporterNameForSriLankaNationality(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);

        $exporter = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $exporter->info->update(['nationality' => Nationality::SriLanka->value]);

        $this->createPdfBatch($exporter);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/export-history');

        $response->assertStatus(200)->assertJsonPath('data.data.0.exporter.name', 'THARINDU RAVISHANN');
    }

    public function testShowUsesShortenedNamesForExporterAndCsvUsers(): void
    {
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);

        $exporter = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $exporter->info->update(['nationality' => Nationality::SriLanka->value]);

        $exportedUser = User::factory()->create([
            'first_name' => 'Wickramage Nimal Perera',
            'last_name' => 'Gunawardana',
        ]);
        $exportedUser->info->update(['nationality' => Nationality::SriLanka->value]);

        $batch = $this->createCsvBatch($exporter);
        $batch->update(['meta' => ['user_ids' => [$exportedUser->id]]]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/export-history/{$batch->id}");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.exporter.name', 'THARINDU RAVISHANN')
            ->assertJsonPath('data.users.0.name', 'NIMAL PERERA');
    }

    public function testShowHidesPdfBatchWithoutPdfPermission(): void
    {
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);

        $batch = $this->createPdfBatch($this->admin);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/export-history/{$batch->id}");

        $response->assertStatus(404);
    }

    public function testShowHidesCsvBatchWithoutCsvPermission(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);

        $batch = $this->createCsvBatch($this->admin);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/export-history/{$batch->id}");

        $response->assertStatus(404);
    }

    public function testWorkHistoryRowsVisibleOnlyToTheirExporter(): void
    {
        $this->grantPermission($this->admin, PermissionName::WorkHistoryExport);

        $other = User::factory()->create();
        $this->grantPermission($other, PermissionName::WorkHistoryExport);

        $ownBatch = $this->createWorkHistoryBatch($this->admin);
        $this->createWorkHistoryBatch($other);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/export-history');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals($ownBatch->id, $response->json('data.data.0.id'));
    }

    public function testWorkHistoryRowsHiddenWithoutWorkHistoryPermission(): void
    {
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);

        $this->createWorkHistoryBatch($this->admin);
        $this->createCsvBatch($this->admin);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/export-history');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals('csv', $response->json('data.data.0.type'));
    }

    public function testFiltersByTypeWorkHistory(): void
    {
        $this->grantPermission($this->admin, PermissionName::UserExportCsv);
        $this->grantPermission($this->admin, PermissionName::WorkHistoryExport);

        $this->createWorkHistoryBatch($this->admin);
        $this->createCsvBatch($this->admin);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/export-history?type=work_history');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals('work_history', $response->json('data.data.0.type'));
    }

    public function testShowReturnsOwnWorkHistoryBatch(): void
    {
        $this->grantPermission($this->admin, PermissionName::WorkHistoryExport);

        $batch = $this->createWorkHistoryBatch($this->admin);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/export-history/{$batch->id}");

        $response->assertStatus(200);
        $this->assertEquals('work_history', $response->json('data.type'));
    }

    public function testShowHidesOtherUsersWorkHistoryBatch(): void
    {
        $this->grantPermission($this->admin, PermissionName::WorkHistoryExport);

        $other = User::factory()->create();
        $batch = $this->createWorkHistoryBatch($other);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/export-history/{$batch->id}");

        $response->assertStatus(404);
    }

    public function testDownloadReturnsUrlForOwnCompletedWorkHistoryBatch(): void
    {
        Storage::fake('local');
        $this->grantPermission($this->admin, PermissionName::WorkHistoryExport);

        $batch = $this->createWorkHistoryBatch($this->admin);
        Storage::put('work-history-exports/1/report.zip', 'zip-content');
        $batch->update(['file_path' => 'work-history-exports/1/report.zip']);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/export-history/{$batch->id}/download");

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.download_url'));
    }

    public function testDownloadRejectsOtherUsersWorkHistoryBatch(): void
    {
        Storage::fake('local');
        $this->grantPermission($this->admin, PermissionName::WorkHistoryExport);

        $other = User::factory()->create();
        $batch = $this->createWorkHistoryBatch($other);
        Storage::put('work-history-exports/1/report.zip', 'zip-content');
        $batch->update(['file_path' => 'work-history-exports/1/report.zip']);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/export-history/{$batch->id}/download");

        $response->assertStatus(404);
    }

    public function testDownloadRejectsIncompleteWorkHistoryBatch(): void
    {
        Storage::fake('local');
        $this->grantPermission($this->admin, PermissionName::WorkHistoryExport);

        $batch = $this->createWorkHistoryBatch($this->admin);
        $batch->update(['status' => ExportBatchStatus::Processing, 'file_path' => null]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/export-history/{$batch->id}/download");

        $response->assertStatus(404);
    }

    private function grantPermission(User $user, PermissionName $permission): void
    {
        $perm = Permission::firstOrCreate(['name' => $permission->value], ['display_name' => $permission->value]);
        $user->permissions()->syncWithoutDetaching([$perm->id]);
    }

    private function createPdfBatch(User $exporter): ExportBatch
    {
        $template = PdfTemplate::query()->create([
            'name' => 'Test Template',
            'file_path' => 'pdf-templates/test.pdf',
            'file_name' => 'test.pdf',
            'page_count' => 1,
        ]);

        return ExportBatch::query()->create([
            'type' => ExportBatchType::Pdf,
            'pdf_template_id' => $template->id,
            'exported_by' => $exporter->id,
            'status' => ExportBatchStatus::Completed,
        ]);
    }

    private function createCsvBatch(User $exporter): ExportBatch
    {
        return ExportBatch::query()->create([
            'type' => ExportBatchType::Csv,
            'exported_by' => $exporter->id,
            'status' => ExportBatchStatus::Completed,
            'csv_total_count' => 10,
            'csv_completed_count' => 10,
            'csv_failed_count' => 0,
        ]);
    }

    private function createWorkHistoryBatch(User $exporter): ExportBatch
    {
        return ExportBatch::query()->create([
            'type' => ExportBatchType::WorkHistory,
            'exported_by' => $exporter->id,
            'status' => ExportBatchStatus::Completed,
            'csv_total_count' => 3,
            'csv_completed_count' => 3,
            'csv_failed_count' => 0,
            'meta' => ['conditions' => ['dates' => ['2026-05-01', '2026-05-31']]],
        ]);
    }
}
