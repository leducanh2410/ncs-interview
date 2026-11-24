<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExamResultStatus;
use App\Enums\Locale;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Exports\ExamResultExport;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Permission;
use App\Models\Question;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExamResultExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $permissionNames = [PermissionName::ExamResultList->value, PermissionName::ExamResultExport->value];
        foreach ($permissionNames as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName], ['display_name' => $permissionName]);
        }

        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $role
            ->permissions()
            ->syncWithoutDetaching(Permission::query()->whereIn('name', $permissionNames)->pluck('id')->all());

        $this->admin = User::factory()->create(['settings' => ['lang' => Locale::English->value]]);
        $this->admin->addRole($role);
    }

    public function testExportReturnsCsvWithTheThreeConfiguredColumns(): void
    {
        $this->makeExamResult('Exam title', ExamResultStatus::Completed, '2026-07-20 09:30:00');

        $response = $this->actingAs($this->admin, 'api')->get('/admin/exam-results/export');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $rows = $this->readCsv($response->streamedContent());
        $this->assertEquals(['Exam name', 'Status', 'Completed at', 'Occurrence', 'Workplace'], $rows[0]);
        $this->assertEquals(['Exam title', 'Completed', '2026-07-20 09:30', '1', ''], $rows[1]);
    }

    public function testExportStartsWithAUtf8BomSoExcelDoesNotMojibakeTheTranslatedText(): void
    {
        $this->makeExamResult('Exam title', ExamResultStatus::Completed, '2026-07-20 09:30:00');
        $this->admin->update(['settings' => ['lang' => Locale::Japanese->value]]);

        $response = $this->actingAs($this->admin, 'api')->get('/admin/exam-results/export');

        $response->assertStatus(200);
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}", $content, 'Expected the CSV to start with a UTF-8 BOM');
        $this->assertStringContainsString('試験名', $content);
    }

    public function testExportUsesTheExamTitleOfTheRequestingUserLanguage(): void
    {
        $this->makeExamResult('Exam title', ExamResultStatus::Completed, '2026-07-20 09:30:00');
        $this->admin->update(['settings' => ['lang' => Locale::Vietnamese->value]]);

        $response = $this->actingAs($this->admin, 'api')->get('/admin/exam-results/export');

        $response->assertStatus(200);
        $rows = $this->readCsv($response->streamedContent());
        $this->assertEquals(['Tên bài thi', 'Trạng thái', 'Hoàn thành lúc', 'Lần thứ', 'Nơi làm việc'], $rows[0]);
        $this->assertEquals(['Bai thi', 'Hoàn thành', '2026-07-20 09:30', '1', ''], $rows[1]);
    }

    public function testExportTranslatesEveryColumnAgainstItsOwnLocaleNotTheAppLocale(): void
    {
        $this->makeExamResult('Exam title', ExamResultStatus::Completed, '2026-07-20 09:30:00');
        $examResults = ExamResult::query()->with('exam.translations')->get();
        App::setLocale(Locale::English->value);

        $export = new ExamResultExport($examResults, Locale::Japanese->value);
        $rows = $this->readCsv(Excel::raw($export, ExcelFormat::CSV));

        $this->assertEquals(['試験名', 'ステータス', '完了日時', '回数', '職場'], $rows[0]);
        $this->assertEquals(['Shiken', '完了', '2026-07-20 09:30', '1', ''], $rows[1]);
        $this->assertEquals(Locale::English->value, App::getLocale(), 'The export must not mutate the app locale');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function formulaPayloadProvider(): array
    {
        return [
            'equals' => ['=1+2', "'=1+2"],
            'plus' => ['+1+2', "'+1+2"],
            'minus' => ['-1+2', "'-1+2"],
            'at' => ['@SUM(A1)', "'@SUM(A1)"],
            'tab' => ["\t=1+2", "'\t=1+2"],
            'command execution' => ['=cmd|\' /c calc\'!A1', "'=cmd|' /c calc'!A1"],
            'harmless title untouched' => ['Bài thi an toàn', 'Bài thi an toàn'],
            'equals in the middle untouched' => ['Bài thi = an toàn', 'Bài thi = an toàn'],
        ];
    }

    #[DataProvider('formulaPayloadProvider')]
    public function testExportNeutralisesFormulaInjectionInTheExamName(string $title, string $expected): void
    {
        $this->makeExamResult($title, ExamResultStatus::Completed, '2026-07-20 09:30:00');

        $response = $this->actingAs($this->admin, 'api')->get('/admin/exam-results/export');

        $response->assertStatus(200);
        $rows = $this->readCsv($response->streamedContent());
        $this->assertEquals($expected, $rows[1][0]);
    }

    public function testExportLeavesCompletedAtEmptyForAnUnfinishedResult(): void
    {
        $this->makeExamResult('Exam title', ExamResultStatus::InProgress, null);

        $response = $this->actingAs($this->admin, 'api')->get('/admin/exam-results/export');

        $response->assertStatus(200);
        $rows = $this->readCsv($response->streamedContent());
        $this->assertEquals(['Exam title', 'In progress', '', '1', ''], $rows[1]);
    }

    public function testExportAppliesTheSameFiltersAsTheListScreen(): void
    {
        $this->makeExamResult('Kept exam', ExamResultStatus::Completed, '2026-07-20 09:30:00');
        $this->makeExamResult('Other exam', ExamResultStatus::Failed, '2026-07-21 09:30:00');

        $response = $this->actingAs($this->admin, 'api')->get(
            '/admin/exam-results/export?status=' . ExamResultStatus::Completed->value
        );

        $response->assertStatus(200);
        $rows = $this->readCsv($response->streamedContent());
        $this->assertCount(2, $rows, 'Expected the heading row plus the single matching result');
        $this->assertEquals(['Kept exam', 'Completed', '2026-07-20 09:30', '1', ''], $rows[1]);
    }

    public function testExportIsForbiddenWithoutTheExportPermission(): void
    {
        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $role
            ->permissions()
            ->detach(Permission::query()->where('name', PermissionName::ExamResultExport->value)->pluck('id')->all());

        $response = $this->actingAs($this->admin, 'api')->get('/admin/exam-results/export');

        $response->assertStatus(403);
    }

    private function makeExamResult(string $englishTitle, ExamResultStatus $status, ?string $completedAt): ExamResult
    {
        $exam = Exam::create(['is_required' => false, 'retry_limit' => 0, 'min_score' => 1, 'time_limit' => 30]);
        $exam->syncTranslations([
            'vi' => 'Bai thi',
            'en' => $englishTitle,
            'ja' => 'Shiken',
            'ne' => 'Pariksha',
            'si' => 'Piksha',
        ]);
        Question::create(['exam_id' => $exam->id]);

        return ExamResult::create([
            'user_id' => User::factory()->create(['paper_status' => UserPaperStatus::Approved->value])->id,
            'exam_id' => $exam->id,
            'score' => 1,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now()->subHour(),
            'expires_at' => now()->addHour(),
            'completed_at' => $completedAt,
            'status' => $status,
        ]);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readCsv(string $content): array
    {
        if (str_starts_with($content, "\u{FEFF}")) {
            $content = substr($content, 3);
        }

        $rows = [];
        foreach (preg_split('/\R/', trim($content)) as $line) {
            $rows[] = str_getcsv($line, ',', '"', '\\');
        }

        return $rows;
    }
}
