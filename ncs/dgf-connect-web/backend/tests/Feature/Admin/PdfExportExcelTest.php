<?php

namespace Tests\Feature\Admin;

use App\Actions\Admin\PdfExport\Excel\CreateExcelBatchExportAction;
use App\Actions\Admin\PdfExport\Excel\ResolveExcelUsersAction;
use App\Actions\Admin\PdfTemplate\GeneratePdfAction;
use App\Enums\ExportBatchSource;
use App\Enums\ExportBatchStatus;
use App\Enums\ExportBatchType;
use App\Enums\PermissionName;
use App\Jobs\ProcessExportChunkJob;
use App\Models\ExportBatch;
use App\Models\ExportBatchItem;
use App\Models\PdfTemplate;
use App\Models\PdfTemplateField;
use App\Models\Permission;
use App\Models\User;
use App\Services\Admin\PdfTemplate\PdfTemplateLockService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Concerns\BuildsUserListWorkbook;
use Tests\TestCase;

class PdfExportExcelTest extends TestCase
{
    use BuildsUserListWorkbook;
    use RefreshDatabase;

    private const PREVIEW_URL = '/admin/pdf-export/excel/preview';

    private const EXPORT_URL = '/admin/pdf-export/excel/batch-export';

    private const SAMPLE_URL = '/admin/pdf-export/excel/sample';

    private const LIMITS_URL = '/admin/pdf-export/excel/limits';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        $this->admin = User::factory()->create();
    }

    public function testPreviewReturns403WithoutPermission(): void
    {
        $template = $this->createTemplate();

        $response = $this->preview($template, $this->userListWorkbook([['1001']]));

        $response->assertStatus(403);
    }

    public function testPreviewRequiresTemplateAndSpreadsheetFile(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();

        $this->actingAs($this->admin, 'api')
            ->post(self::PREVIEW_URL, ['template_id' => $template->id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'E_UNPROCESSABLE_ENTITY')
            ->assertJsonStructure(['data' => ['file']]);

        $this->actingAs($this->admin, 'api')
            ->post(self::PREVIEW_URL, ['file' => $this->userListWorkbook([['1001']])])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['template_id']]);

        $this->actingAs($this->admin, 'api')
            ->post(self::PREVIEW_URL, [
                'template_id' => $template->id,
                'file' => UploadedFile::fake()->create('list.txt', 1, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['file']]);
    }

    public function testPreviewAcceptsCsvFiles(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();
        $taro = $this->createUser('1001', 'YAMADA', 'TARO');

        $path = tempnam(sys_get_temp_dir(), 'userlist') . '.csv';
        file_put_contents($path, "staff_no,name\n1001,YAMADA TARO\n9999,NOBODY\n");
        $file = new UploadedFile($path, 'list.csv', 'text/csv', null, true);

        $response = $this->preview($template, $file);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.summary.matched_count', 1)
            ->assertJsonPath('data.summary.unmatched_count', 1)
            ->assertJsonPath('data.matched.0.user.id', $taro->id);
    }

    public function testPreviewRejectsPlainTextRenamedAsSpreadsheet(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();

        $path = tempnam(sys_get_temp_dir(), 'userlist') . '.txt';
        file_put_contents($path, "1001\n1002\n");

        $response = $this->preview($template, new UploadedFile($path, 'list.txt', 'text/plain', null, true));

        $response->assertStatus(422)->assertJsonStructure(['data' => ['file']]);
    }

    public function testPreviewGroupsRowsIntoMatchedUnmatchedAndDuplicates(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();

        $taro = $this->createUser('1001', 'YAMADA', 'TARO');
        $hanako = $this->createUser('1002', 'SUZUKI', 'HANAKO', isDisabled: true);
        $this->createUser('1003', 'SATO', 'JIRO');

        $file = $this->userListWorkbook([
            ['社員番号', '氏名'],
            ['1001', 'Yamada Taro'],
            ['1002', 'Tanaka Hanako'],
            ['9999', 'Nobody'],
            ['1001', 'Yamada Taro'],
            [null, 'Missing Staff No'],
            [null, null],
            ['1003'],
        ]);

        $response = $this->preview($template, $file);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.template.id', $template->id)
            ->assertJsonPath('data.file_name', 'user-list.xlsx')
            ->assertJsonPath('data.can_export', true)
            ->assertJsonPath('data.summary.total_rows', 6)
            ->assertJsonPath('data.summary.matched_count', 3)
            ->assertJsonPath('data.summary.unmatched_count', 2)
            ->assertJsonPath('data.summary.duplicate_count', 1)
            ->assertJsonPath('data.summary.disabled_count', 1)
            ->assertJsonPath('data.summary.name_mismatch_count', 1);

        $matched = collect($response->json('data.matched'))->keyBy('staff_no');
        $this->assertSame([2, 3, 8], collect($response->json('data.matched'))->pluck('row_number')->all());
        $this->assertSame($taro->id, $matched['1001']['user']['id']);
        $this->assertFalse($matched['1001']['name_mismatch']);
        $this->assertSame('exact', $matched['1001']['matched_by']);
        $this->assertSame($hanako->id, $matched['1002']['user']['id']);
        $this->assertTrue($matched['1002']['name_mismatch']);
        $this->assertTrue($matched['1002']['is_disabled']);
        $this->assertFalse($matched['1003']['name_mismatch']);

        $unmatched = collect($response->json('data.unmatched'))->keyBy('row_number');
        $this->assertSame('not_found', $unmatched[4]['reason']);
        $this->assertSame('9999', $unmatched[4]['staff_no']);
        $this->assertSame('missing_staff_no', $unmatched[6]['reason']);

        $duplicates = $response->json('data.duplicates');
        $this->assertCount(1, $duplicates);
        $this->assertSame(5, $duplicates[0]['row_number']);
        $this->assertSame(2, $duplicates[0]['first_row_number']);
    }

    public function testPreviewMatchesNumericStaffNoWhenExcelDropsLeadingZeros(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();

        $padded = $this->createUser('0012', 'YAMADA', 'TARO');
        $this->createUser('7', 'SUZUKI', 'HANAKO');

        $response = $this->preview($template, $this->userListWorkbook([[12, 'YAMADA TARO'], [7.0, 'SUZUKI HANAKO']]));

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.summary.matched_count', 2)
            ->assertJsonPath('data.summary.numeric_match_count', 1);

        $matched = collect($response->json('data.matched'))->keyBy('row_number');
        $this->assertSame($padded->id, $matched[1]['user']['id']);
        $this->assertSame('numeric', $matched[1]['matched_by']);
        $this->assertSame('12', $matched[1]['staff_no']);
        $this->assertSame('exact', $matched[2]['matched_by']);
        $this->assertSame('7', $matched[2]['staff_no']);
    }

    public function testPreviewMarksAmbiguousNumericMatchesAsUnmatched(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();

        $this->createUser('0012', 'YAMADA', 'TARO');
        $this->createUser('012', 'SUZUKI', 'HANAKO');

        $response = $this->preview($template, $this->userListWorkbook([[12]]));

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.summary.matched_count', 0)
            ->assertJsonPath('data.summary.unmatched_count', 1)
            ->assertJsonPath('data.can_export', false)
            ->assertJsonPath('data.unmatched.0.reason', 'ambiguous');

        $this->assertCount(2, $response->json('data.unmatched.0.candidates'));
    }

    public function testPreviewToleratesNameFormattingDifferences(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();

        $this->createUser('1001', 'NGUYEN', 'VAN AN', 'グエン', 'バンアン');
        $this->createUser('1002', 'YAMADA', 'TARO', 'ヤマダ', 'タロウ');

        $response = $this->preview(
            $template,
            $this->userListWorkbook([['1001', 'Nguyễn Văn An'], ['1002', 'ﾔﾏﾀﾞ　ﾀﾛｳ'], ['1002', 'Taro Yamada']])
        );

        $response->assertStatus(200)->assertJsonPath('data.summary.name_mismatch_count', 0);
    }

    public function testPreviewMatchesStaffNoRegardlessOfLetterCase(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();
        $user = $this->createUser('A001', 'YAMADA', 'TARO');

        $response = $this->preview($template, $this->userListWorkbook([['a001', 'YAMADA TARO']]));

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.summary.matched_count', 1)
            ->assertJsonPath('data.matched.0.user.id', $user->id)
            ->assertJsonPath('data.matched.0.matched_by', 'exact');
    }

    public function testPreviewSkipsTitleAndBlankRowsBeforeTheHeader(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();
        $this->createUser('1001', 'YAMADA', 'TARO');
        $this->createUser('ABC', 'SUZUKI', 'HANAKO');

        $response = $this->preview(
            $template,
            $this->userListWorkbook([['社員一覧'], [null, null], ['社員番号', '氏名'], ['1001'], ['ABC']])
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.summary.total_rows', 2)
            ->assertJsonPath('data.summary.matched_count', 2)
            ->assertJsonPath('data.summary.unmatched_count', 0);
    }

    public function testPreviewKeepsAlphabeticStaffNoOnFirstDataRow(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();
        $abc = $this->createUser('ABC', 'SUZUKI', 'HANAKO');
        $this->createUser('1001', 'YAMADA', 'TARO');

        $response = $this->preview($template, $this->userListWorkbook([['社員番号', '氏名'], ['ABC'], ['1001']]));

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.summary.total_rows', 2)
            ->assertJsonPath('data.summary.matched_count', 2)
            ->assertJsonPath('data.matched.0.row_number', 2)
            ->assertJsonPath('data.matched.0.user.id', $abc->id);
    }

    public function testPreviewKeepsFileWithOnlyAlphabeticStaffNumbers(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();
        $this->createUser('ABC', 'SUZUKI', 'HANAKO');

        $response = $this->preview($template, $this->userListWorkbook([['ABC'], ['XYZ']]));

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.summary.total_rows', 2)
            ->assertJsonPath('data.summary.matched_count', 1)
            ->assertJsonPath('data.unmatched.0.staff_no', 'XYZ')
            ->assertJsonPath('data.unmatched.0.reason', 'not_found');
    }

    public function testPreviewReportsLeadingRowWithNameButNoStaffNo(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();
        $this->createUser('1001', 'YAMADA', 'TARO');

        $response = $this->preview(
            $template,
            $this->userListWorkbook([['社員番号', '氏名'], [null, 'SUZUKI HANAKO'], ['1001', 'YAMADA TARO']])
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.summary.total_rows', 2)
            ->assertJsonPath('data.summary.matched_count', 1)
            ->assertJsonPath('data.summary.unmatched_count', 1)
            ->assertJsonPath('data.unmatched.0.row_number', 2)
            ->assertJsonPath('data.unmatched.0.name', 'SUZUKI HANAKO')
            ->assertJsonPath('data.unmatched.0.reason', 'missing_staff_no');
    }

    public function testPreviewSkipsHeaderRowsWrittenInLatinScript(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();
        $this->createUser('ABC', 'SUZUKI', 'HANAKO');

        foreach ([['Staff No.', 'Name'], ['Mã nhân viên', 'Họ tên'], ['ID', 'Full name'], [null, 'Name']] as $header) {
            $response = $this->preview($template, $this->userListWorkbook([$header, ['ABC']]));

            $response
                ->assertStatus(200)
                ->assertJsonPath('data.summary.total_rows', 1)
                ->assertJsonPath('data.summary.matched_count', 1)
                ->assertJsonPath('data.matched.0.row_number', 2);
        }
    }

    public function testPreviewReadsShiftJisCsvWithJapaneseNamesAndFullWidthDigits(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();
        $taro = $this->createUser('1001', '山田', '太郎');

        $path = tempnam(sys_get_temp_dir(), 'userlist') . '.csv';
        file_put_contents(
            $path,
            mb_convert_encoding("社員番号,氏名\r\n１００１,山田　太郎\r\n１００２,鈴木　花子\r\n", 'CP932', 'UTF-8')
        );

        $response = $this->preview($template, new UploadedFile($path, 'list.csv', 'text/csv', null, true));

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.summary.total_rows', 2)
            ->assertJsonPath('data.summary.matched_count', 1)
            ->assertJsonPath('data.summary.name_mismatch_count', 0)
            ->assertJsonPath('data.matched.0.staff_no', '1001')
            ->assertJsonPath('data.matched.0.name', '山田 太郎')
            ->assertJsonPath('data.matched.0.user.id', $taro->id)
            ->assertJsonPath('data.unmatched.0.staff_no', '1002')
            ->assertJsonPath('data.unmatched.0.name', '鈴木 花子');
    }

    public function testPreviewReadsUtf8CsvWithBom(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();
        $taro = $this->createUser('1001', '山田', '太郎');

        $path = tempnam(sys_get_temp_dir(), 'userlist') . '.csv';
        file_put_contents($path, "\xEF\xBB\xBF社員番号,氏名\n1001,山田 太郎\n");

        $response = $this->preview($template, new UploadedFile($path, 'list.csv', 'text/csv', null, true));

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.summary.matched_count', 1)
            ->assertJsonPath('data.summary.name_mismatch_count', 0)
            ->assertJsonPath('data.matched.0.user.id', $taro->id);
    }

    public function testPreviewReadsOnlyTheFirstSheet(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();
        $this->createUser('1001', 'YAMADA', 'TARO');
        $this->createUser('1002', 'SUZUKI', 'HANAKO');

        $file = $this->userListWorkbook([['1001']], secondSheetRows: [['1002']]);

        $response = $this->preview($template, $file);

        $response->assertStatus(200)->assertJsonPath('data.summary.total_rows', 1);
    }

    public function testBatchExportRollsBackAndRemovesSourceFileWhenItemInsertFails(): void
    {
        Queue::fake();
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();
        $this->createUser('1001', 'YAMADA', 'TARO');

        $resolver = Mockery::mock(ResolveExcelUsersAction::class);
        $resolver
            ->shouldReceive('__invoke')
            ->once()
            ->andReturn([
                'matched' => [],
                'unmatched' => [],
                'duplicates' => [],
                'user_ids' => [999999],
                'summary' => [],
            ]);
        $this->app->instance(ResolveExcelUsersAction::class, $resolver);

        try {
            $this->actingAs($this->admin, 'api');
            app(CreateExcelBatchExportAction::class)($template, $this->userListWorkbook([['1001']]));
            $this->fail('Expected the foreign key violation to propagate.');
        } catch (QueryException) {
        }

        $this->assertDatabaseCount('export_batches', 0);
        $this->assertDatabaseCount('export_batch_items', 0);
        $this->assertSame([], Storage::allFiles());
        Queue::assertNothingPushed();
    }

    public function testPreviewRejectsWorkbookWithoutDataRows(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();

        $response = $this->preview($template, $this->userListWorkbook([['Staff No', 'Name']]));

        $response->assertStatus(422)->assertJsonPath('error', 'PDF_EXPORT_EXCEL_EMPTY');
    }

    public function testPreviewRejectsWorkbookOverRowLimit(): void
    {
        config(['pdf_export.excel_max_rows' => 2]);
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();

        $response = $this->preview($template, $this->userListWorkbook([['1'], ['2'], ['3']]));

        $response
            ->assertStatus(422)
            ->assertJsonPath('error', 'PDF_EXPORT_EXCEL_TOO_MANY_ROWS')
            ->assertJsonPath('data.max_rows', 2)
            ->assertJsonPath('data.row_count', 3);
    }

    public function testPreviewReportsLockedTemplate(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();
        $this->createUser('1001', 'YAMADA', 'TARO');

        $other = User::factory()->create();
        app(PdfTemplateLockService::class)->acquire($template->id, $other->id, 'editing', $other->name);

        $response = $this->preview($template, $this->userListWorkbook([['1001']]));

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.summary.matched_count', 1)
            ->assertJsonPath('data.can_export', false)
            ->assertJsonPath('data.lock_info.user_id', $other->id);
    }

    public function testBatchExportReturns403WithoutPermission(): void
    {
        $template = $this->createTemplate();

        $this->export($template, $this->userListWorkbook([['1001']]))->assertStatus(403);
    }

    public function testBatchExportCreatesExcelSourcedBatchInSpreadsheetOrder(): void
    {
        Queue::fake();
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplateWithField();

        $second = $this->createUser('2002', 'SUZUKI', 'HANAKO', isDisabled: true);
        $first = $this->createUser('1001', 'YAMADA', 'TARO');
        $third = $this->createUser('0033', 'SATO', 'JIRO');

        $file = $this->userListWorkbook(
            [['Staff No', 'Name'], ['1001', 'Yamada Taro'], ['2002', 'Wrong Name'], ['9999', 'Nobody'], [33], ['1001']],
            'targets.xlsx'
        );

        $response = $this->export($template, $file);

        $response
            ->assertStatus(202)
            ->assertJsonPath('data.source', ExportBatchSource::Excel->value)
            ->assertJsonPath('data.pdf_template_id', $template->id)
            ->assertJsonPath('data.meta.source_file_name', 'targets.xlsx')
            ->assertJsonPath('data.meta.excel.summary.matched_count', 3)
            ->assertJsonPath('data.meta.excel.summary.unmatched_count', 1)
            ->assertJsonPath('data.meta.excel.summary.duplicate_count', 1)
            ->assertJsonPath('data.meta.excel.summary.disabled_count', 1)
            ->assertJsonPath('data.meta.excel.unmatched.0.staff_no', '9999')
            ->assertJsonPath('data.meta.excel.duplicates.0.row_number', 6)
            ->assertJsonPath('data.meta.excel.name_mismatches.0.staff_no', '2002')
            ->assertJsonPath('data.meta.excel.name_mismatches.0.user.id', $second->id)
            ->assertJsonPath('data.meta.excel.truncated', false);

        $batch = ExportBatch::query()->findOrFail($response->json('data.id'));
        $this->assertSame($this->admin->id, $batch->exported_by);
        $this->assertSame(ExportBatchStatus::Pending, $batch->status);
        $this->assertTrue($batch->isFromExcel());

        $storedPath = $batch->sourceFilePath();
        $this->assertNotNull($storedPath);
        $this->assertStringStartsWith('pdf-export-sources/', $storedPath);
        Storage::assertExists($storedPath);

        $itemUserIds = ExportBatchItem::query()
            ->where('export_batch_id', $batch->id)
            ->orderBy('id')
            ->pluck('user_id')
            ->all();
        $this->assertSame([$first->id, $second->id, $third->id], $itemUserIds);

        $this->assertNotNull(app(PdfTemplateLockService::class)->get($template->id));
        Queue::assertPushed(ProcessExportChunkJob::class);
    }

    public function testBatchExportReturns422AndStoresNothingWhenNoUsersMatch(): void
    {
        Queue::fake();
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();
        $this->createUser('1001', 'YAMADA', 'TARO');

        $response = $this->export($template, $this->userListWorkbook([['9998'], ['9999']]));

        $response
            ->assertStatus(422)
            ->assertJsonPath('error', 'PDF_EXPORT_EXCEL_NO_USERS_MATCHED')
            ->assertJsonPath('data.summary.unmatched_count', 2);

        $this->assertDatabaseCount('export_batches', 0);
        $this->assertSame([], Storage::allFiles());
        $this->assertNull(app(PdfTemplateLockService::class)->get($template->id));
        Queue::assertNothingPushed();
    }

    public function testBatchExportReturns423AndStoresNothingWhenTemplateLocked(): void
    {
        Queue::fake();
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $template = $this->createTemplate();
        $this->createUser('1001', 'YAMADA', 'TARO');

        $other = User::factory()->create();
        app(PdfTemplateLockService::class)->acquire($template->id, $other->id, 'editing', $other->name);

        $response = $this->export($template, $this->userListWorkbook([['1001']]));

        $response->assertStatus(423)->assertJsonPath('error', 'PDF_TEMPLATE_LOCKED');
        $this->assertDatabaseCount('export_batches', 0);
        $this->assertSame([], Storage::allFiles());
        Queue::assertNothingPushed();
    }

    public function testExcelBatchRunsThroughExportJobAndAppearsInHistory(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);
        $template = $this->createTemplateWithField();

        $this->createUser('1001', 'YAMADA', 'TARO');
        $this->createUser('1002', 'SUZUKI', 'HANAKO');

        $generatePdf = Mockery::mock(GeneratePdfAction::class);
        $generatePdf->shouldReceive('__invoke')->twice()->andReturn('%PDF-1.4 generated');
        $this->app->instance(GeneratePdfAction::class, $generatePdf);

        $batchId = $this->export($template, $this->userListWorkbook([['1001'], ['1002'], ['9999']]))
            ->assertStatus(202)
            ->json('data.id');

        $batch = ExportBatch::query()->findOrFail($batchId);
        $this->assertSame(ExportBatchStatus::Completed, $batch->status);
        $this->assertSame(2, $batch->completed_count);
        $this->assertNotEmpty($batch->zip_file_paths);
        $this->assertNull(app(PdfTemplateLockService::class)->get($template->id));

        $this->actingAs($this->admin, 'api')
            ->getJson('/admin/export-history?source=excel')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $batchId)
            ->assertJsonPath('data.data.0.source', 'excel');

        $this->actingAs($this->admin, 'api')
            ->getJson('/admin/export-history?source=manual')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 0);

        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/export-history/{$batchId}")
            ->assertStatus(200)
            ->assertJsonPath('data.source', 'excel')
            ->assertJsonPath('data.meta.excel.summary.matched_count', 2)
            ->assertJsonPath('data.meta.excel.unmatched.0.staff_no', '9999');

        $download = $this->actingAs($this->admin, 'api')
            ->getJson("/admin/export-history/{$batchId}/source-file")
            ->assertStatus(200)
            ->assertJsonPath('data.file_name', 'user-list.xlsx');
        $this->assertNotEmpty($download->json('data.download_url'));
    }

    public function testSourceFileDownloadRequiresListPermission(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);
        Queue::fake();
        $template = $this->createTemplate();
        $this->createUser('1001', 'YAMADA', 'TARO');

        $batchId = $this->export($template, $this->userListWorkbook([['1001']]))
            ->assertStatus(202)
            ->json('data.id');

        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/export-history/{$batchId}/source-file")
            ->assertStatus(403);
    }

    public function testSourceFileDownloadReturns404ForManualBatch(): void
    {
        $this->grantPermission($this->admin, PermissionName::PdfTemplateList);
        $template = $this->createTemplate();

        $batch = ExportBatch::query()->create([
            'type' => ExportBatchType::Pdf,
            'pdf_template_id' => $template->id,
            'exported_by' => $this->admin->id,
            'status' => ExportBatchStatus::Completed,
        ]);

        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/export-history/{$batch->id}/source-file")
            ->assertStatus(404);
    }

    public function testExcelSampleDownloadsWorkbook(): void
    {
        $this->actingAs($this->admin, 'api')->get(self::SAMPLE_URL)->assertStatus(403);

        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $response = $this->actingAs($this->admin, 'api')->get(self::SAMPLE_URL);

        $response->assertStatus(200);
        $this->assertStringContainsString(
            'pdf_export_user_list_sample.xlsx',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    public function testExcelLimitsExposeConfiguredValues(): void
    {
        config(['pdf_export.excel_max_rows' => 1200, 'pdf_export.excel_max_file_size_kb' => 10240]);

        $this->actingAs($this->admin, 'api')->get(self::LIMITS_URL)->assertStatus(403);

        $this->grantPermission($this->admin, PermissionName::PdfTemplateExport);

        $this->actingAs($this->admin, 'api')
            ->get(self::LIMITS_URL)
            ->assertStatus(200)
            ->assertJsonPath('data.max_rows', 1200)
            ->assertJsonPath('data.max_file_size_kb', 10240);
    }

    private function preview(PdfTemplate $template, UploadedFile $file): TestResponse
    {
        return $this->actingAs($this->admin, 'api')->post(self::PREVIEW_URL, [
            'template_id' => $template->id,
            'file' => $file,
        ]);
    }

    private function export(PdfTemplate $template, UploadedFile $file): TestResponse
    {
        return $this->actingAs($this->admin, 'api')->post(self::EXPORT_URL, [
            'template_id' => $template->id,
            'file' => $file,
        ]);
    }

    private function createUser(
        string $staffNo,
        string $lastName,
        string $firstName,
        ?string $lastNameKana = null,
        ?string $firstNameKana = null,
        bool $isDisabled = false
    ): User {
        return User::factory()->create([
            'staff_no' => $staffNo,
            'last_name' => $lastName,
            'first_name' => $firstName,
            'last_name_kana' => $lastNameKana ?? $lastName,
            'first_name_kana' => $firstNameKana ?? $firstName,
            'is_disabled' => $isDisabled,
        ]);
    }

    private function grantPermission(User $user, PermissionName $permission): void
    {
        $perm = Permission::firstOrCreate(['name' => $permission->value], ['display_name' => $permission->value]);
        $user->permissions()->syncWithoutDetaching([$perm->id]);
    }

    private function createTemplate(array $attrs = []): PdfTemplate
    {
        return PdfTemplate::query()->create(
            array_merge(
                [
                    'name' => 'Excel Template',
                    'file_path' => 'pdf-templates/test.pdf',
                    'file_name' => 'test.pdf',
                    'page_count' => 1,
                ],
                $attrs
            )
        );
    }

    private function createTemplateWithField(): PdfTemplate
    {
        $template = $this->createTemplate();

        PdfTemplateField::query()->create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10,
            'y' => 20,
            'width' => 30,
            'height' => 5,
            'data_source_key' => 'user.email',
            'fill_mode' => 'text',
            'text_config' => json_encode([
                'font_weight' => 'normal',
                'font_style' => 'normal',
                'font_size' => 10,
                'h_align' => 'left',
                'v_align' => 'top',
                'letter_spacing' => 0,
                'overflow' => 'wrap',
            ]),
        ]);

        return $template;
    }
}
