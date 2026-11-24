<?php

namespace Tests\Unit\Jobs;

use App\Enums\ExportBatchStatus;
use App\Enums\ExportBatchType;
use App\Enums\Nationality;
use App\Jobs\CsvExportJob;
use App\Models\ExportBatch;
use App\Models\User;
use App\Services\Csv\CsvPropertyRegistry;
use App\Services\Csv\CsvPropertyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CsvExportJobTest extends TestCase
{
    use RefreshDatabase;

    private CsvPropertyRegistry $registry;

    private CsvPropertyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Log::spy();
        Queue::fake();

        $this->registry = new CsvPropertyRegistry();
        $this->resolver = new CsvPropertyResolver($this->registry);
    }

    private function createCsvBatch(User $exporter, array $meta = []): ExportBatch
    {
        $defaultMeta = [
            'selected_keys' => ['last_name', 'first_name', 'email'],
            'ordered_keys' => ['last_name', 'first_name', 'email'],
            'export_keys' => ['last_name', 'first_name', 'email'],
            'user_ids' => [],
            'select_all' => true,
            'filters' => ['is_disabled' => false],
        ];

        return ExportBatch::query()->create([
            'type' => ExportBatchType::Csv,
            'exported_by' => $exporter->id,
            'status' => ExportBatchStatus::Pending,
            'csv_total_count' => 0,
            'csv_completed_count' => 0,
            'csv_failed_count' => 0,
            'meta' => array_merge($defaultMeta, $meta),
        ]);
    }

    public function testUpdatesBatchStatusToProcessing(): void
    {
        $exporter = User::factory()->create();
        $user = User::factory()->create(['is_disabled' => false]);
        $batch = $this->createCsvBatch($exporter, [
            'user_ids' => [$user->id],
            'select_all' => false,
        ]);
        $batch->update(['csv_total_count' => 1]);

        $job = new CsvExportJob($batch->id);
        $job->handle($this->registry, $this->resolver);

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);
    }

    public function testCompletesWithUserIdsSelection(): void
    {
        $exporter = User::factory()->create();
        $user1 = User::factory()->create([
            'first_name' => 'Taro',
            'last_name' => 'Tanaka',
            'email' => 'taro@example.com',
            'is_disabled' => false,
        ]);
        $user2 = User::factory()->create([
            'first_name' => 'Jiro',
            'last_name' => 'Suzuki',
            'email' => 'jiro@example.com',
            'is_disabled' => false,
        ]);

        $batch = $this->createCsvBatch($exporter, [
            'user_ids' => [$user1->id, $user2->id],
            'select_all' => false,
        ]);
        $batch->update(['csv_total_count' => 2]);

        $job = new CsvExportJob($batch->id);
        $job->handle($this->registry, $this->resolver);

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);
        $this->assertEquals(2, $batch->csv_completed_count);
        $this->assertEquals(0, $batch->csv_failed_count);
        $this->assertNotNull($batch->file_path);
    }

    public function testCompletesWithSelectAllAndFilters(): void
    {
        $exporter = User::factory()->create();
        User::factory()->create(['last_name' => 'Tanaka', 'is_disabled' => false]);
        User::factory()->create(['last_name' => 'Suzuki', 'is_disabled' => false]);
        User::factory()->create(['last_name' => 'Yamada', 'is_disabled' => true]);

        $batch = $this->createCsvBatch($exporter, [
            'select_all' => true,
            'filters' => ['is_disabled' => false],
        ]);
        $batch->update(['csv_total_count' => 2]);

        $job = new CsvExportJob($batch->id);
        $job->handle($this->registry, $this->resolver);

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Completed, $batch->status);
        $this->assertGreaterThanOrEqual(2, $batch->csv_completed_count);
    }

    public function testCsvFileContainsBomAndCrlf(): void
    {
        $exporter = User::factory()->create();
        $user = User::factory()->create([
            'first_name' => 'Taro',
            'last_name' => 'Tanaka',
            'email' => 'taro@example.com',
            'is_disabled' => false,
        ]);

        $batch = $this->createCsvBatch($exporter, [
            'user_ids' => [$user->id],
            'select_all' => false,
        ]);
        $batch->update(['csv_total_count' => 1]);

        $job = new CsvExportJob($batch->id);
        $job->handle($this->registry, $this->resolver);

        $batch->refresh();
        $this->assertNotNull($batch->file_path);

        $content = Storage::get($batch->file_path);

        // UTF-8 BOM
        $this->assertEquals("\xEF\xBB\xBF", mb_substr($content, 0, 3, '8bit'));
        // CRLF line endings
        $this->assertStringContainsString("\r\n", $content);
    }

    public function testCsvHeaderRowContainsJapaneseLabels(): void
    {
        $exporter = User::factory()->create();
        $user = User::factory()->create([
            'first_name' => 'Taro',
            'last_name' => 'Tanaka',
            'email' => 'taro@example.com',
            'is_disabled' => false,
        ]);

        $batch = $this->createCsvBatch($exporter, [
            'user_ids' => [$user->id],
            'select_all' => false,
        ]);
        $batch->update(['csv_total_count' => 1]);

        $job = new CsvExportJob($batch->id);
        $job->handle($this->registry, $this->resolver);

        $batch->refresh();
        $content = Storage::get($batch->file_path);

        // Remove BOM for parsing
        $content = mb_substr($content, 3, null, '8bit');
        $lines = explode("\r\n", $content);
        $header = $lines[0];

        // Japanese labels for last_name, first_name, email
        $this->assertStringContainsString('姓', $header);
        $this->assertStringContainsString('名', $header);
        $this->assertStringContainsString('メール', $header);
    }

    public function testCsvColumnOrderMatchesExportKeys(): void
    {
        $exporter = User::factory()->create();
        $user = User::factory()->create([
            'first_name' => 'Taro',
            'last_name' => 'Tanaka',
            'email' => 'taro@example.com',
            'is_disabled' => false,
        ]);

        $batch = $this->createCsvBatch($exporter, [
            'user_ids' => [$user->id],
            'select_all' => false,
            'ordered_keys' => ['email', 'last_name', 'first_name'],
            'export_keys' => ['email', 'last_name', 'first_name'],
        ]);
        $batch->update(['csv_total_count' => 1]);

        $job = new CsvExportJob($batch->id);
        $job->handle($this->registry, $this->resolver);

        $batch->refresh();
        $content = Storage::get($batch->file_path);
        $content = mb_substr($content, 3, null, '8bit');
        $lines = explode("\r\n", $content);
        $header = $lines[0];

        // Order should be email, last_name, first_name
        $emailPos = mb_strpos($header, 'メール');
        $lastNamePos = mb_strpos($header, '姓');
        $firstNamePos = mb_strpos($header, '名');

        $this->assertLessThan($lastNamePos, $emailPos);
        $this->assertLessThan($firstNamePos, $lastNamePos);
    }

    public function testCsvExportsShortenedNameForSriLankaUser(): void
    {
        $exporter = User::factory()->create();
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
            'is_disabled' => false,
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        $batch = $this->createCsvBatch($exporter, [
            'user_ids' => [$user->id],
            'select_all' => false,
            'ordered_keys' => ['short_name', 'first_name', 'last_name'],
            'export_keys' => ['short_name', 'first_name', 'last_name'],
        ]);
        $batch->update(['csv_total_count' => 1]);

        $job = new CsvExportJob($batch->id);
        $job->handle($this->registry, $this->resolver);

        $batch->refresh();
        $content = Storage::get($batch->file_path);
        $content = mb_substr($content, 3, null, '8bit');
        $lines = explode("\r\n", $content);

        // short_name column (first) holds the shortened name; first_name/last_name stay raw.
        $this->assertStringContainsString('"THARINDU RAVISHANN"', $lines[1]);
        $this->assertStringContainsString('RAGALAGE THARINDU RAVISHANN', $lines[1]);
        $this->assertStringContainsString('KARNASOORIYA', $lines[1]);
    }

    public function testTempFileIsRemovedAfterSuccess(): void
    {
        $exporter = User::factory()->create();
        $user = User::factory()->create(['is_disabled' => false]);

        $batch = $this->createCsvBatch($exporter, [
            'user_ids' => [$user->id],
            'select_all' => false,
        ]);
        $batch->update(['csv_total_count' => 1]);

        $tempPath = storage_path('app/tmp/csv-exports/' . $batch->id . '.csv');

        $job = new CsvExportJob($batch->id);
        $job->handle($this->registry, $this->resolver);

        $this->assertFileDoesNotExist($tempPath);
    }

    public function testFailedMethodSetsStatusToFailed(): void
    {
        $exporter = User::factory()->create();
        $batch = $this->createCsvBatch($exporter);
        $batch->update(['csv_total_count' => 5]);

        $job = new CsvExportJob($batch->id);
        $job->failed(new \RuntimeException('S3 connection timeout'));

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Failed, $batch->status);
        $this->assertEquals('S3 connection timeout', $batch->error_message);
        $this->assertEquals(0, $batch->csv_completed_count);
        $this->assertEquals(5, $batch->csv_failed_count);
    }

    public function testFailedMethodCleansUpFilePath(): void
    {
        $exporter = User::factory()->create();
        $batch = $this->createCsvBatch($exporter);
        $batch->update([
            'file_path' => 'csv-exports/1/test.csv',
            'csv_total_count' => 3,
        ]);
        Storage::put('csv-exports/1/test.csv', 'test content');

        $job = new CsvExportJob($batch->id);
        $job->failed(new \RuntimeException('Upload failed'));

        $batch->refresh();
        $this->assertNull($batch->file_path);
        Storage::assertMissing('csv-exports/1/test.csv');
    }

    public function testJobReturnsEarlyForNonCsvBatch(): void
    {
        $exporter = User::factory()->create();
        $batch = ExportBatch::query()->create([
            'type' => ExportBatchType::Pdf,
            'exported_by' => $exporter->id,
            'status' => ExportBatchStatus::Pending,
        ]);

        $job = new CsvExportJob($batch->id);
        $job->handle($this->registry, $this->resolver);

        $batch->refresh();
        $this->assertEquals(ExportBatchStatus::Pending, $batch->status);

        Log::shouldHaveReceived('error')->once();
    }

    public function testJobHasCorrectTriesAndBackoff(): void
    {
        $exporter = User::factory()->create();
        $batch = $this->createCsvBatch($exporter);

        $job = new CsvExportJob($batch->id);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals([10, 30, 60], $job->backoff());
    }
}
