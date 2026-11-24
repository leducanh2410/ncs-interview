<?php

namespace Tests\Unit\Actions\Admin\PdfTemplate;

use App\Actions\Admin\PdfTemplate\GeneratePdfAction;
use App\Enums\PdfTemplateFieldFillMode;
use App\Enums\PdfTemplateFieldShape;
use App\Models\PdfTemplate;
use App\Models\PdfTemplateField;
use App\Models\User;
use App\Services\Admin\PdfTemplate\DataSourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class GeneratePdfActionTest extends TestCase
{
    use RefreshDatabase;

    private GeneratePdfAction $action;

    private $dataSourceResolver;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->dataSourceResolver = Mockery::mock(DataSourceResolver::class);
        $this->action = new GeneratePdfAction($this->dataSourceResolver);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createTemplateWithPdf(string $name = 'Test Template'): PdfTemplate
    {
        $pdfContent = file_get_contents(base_path('tests/Fixtures/test_template.pdf'));
        $filePath = 'pdf-templates/test.pdf';
        Storage::put($filePath, $pdfContent);

        return PdfTemplate::create([
            'name' => $name,
            'file_path' => $filePath,
            'file_name' => 'test.pdf',
            'page_count' => 1,
        ]);
    }

    public function testGeneratesValidPdfBinaryWithUser(): void
    {
        $template = $this->createTemplateWithPdf();
        $user = User::factory()->create(['email' => 'test@example.com']);

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10.0,
            'y' => 10.0,
            'width' => 30.0,
            'height' => 5.0,
            'data_source_key' => 'user.email',
            'fill_mode' => 'text',
            'text_config' => [
                'font_weight' => 'normal',
                'font_size' => 12,
                'h_align' => 'left',
                'v_align' => 'top',
                'overflow' => 'trim',
            ],
        ]);

        $this->dataSourceResolver
            ->shouldReceive('resolveAll')
            ->once()
            ->andReturn(['user.email' => 'test@example.com']);

        $result = ($this->action)($template, $user);

        $this->assertNotEmpty($result);
        $this->assertStringStartsWith('%PDF', $result);
    }

    public function testGeneratesPdfWithPlaceholdersWhenNoUser(): void
    {
        $template = $this->createTemplateWithPdf();

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10.0,
            'y' => 10.0,
            'width' => 30.0,
            'height' => 5.0,
            'data_source_key' => 'user.email',
            'placeholder' => 'custom@placeholder.com',
            'fill_mode' => 'text',
            'text_config' => [
                'font_weight' => 'normal',
                'font_size' => 12,
                'h_align' => 'left',
                'v_align' => 'top',
                'overflow' => 'trim',
            ],
        ]);

        // resolveAll should NOT be called when no user is provided
        $this->dataSourceResolver->shouldNotReceive('resolveAll');

        $result = ($this->action)($template);

        $this->assertNotEmpty($result);
        $this->assertStringStartsWith('%PDF', $result);
    }

    public function testUsesFieldPlaceholderOverDataSourceDefault(): void
    {
        $template = $this->createTemplateWithPdf();

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10.0,
            'y' => 10.0,
            'width' => 30.0,
            'height' => 5.0,
            'data_source_key' => 'user.email',
            'placeholder' => 'custom-placeholder',
            'fill_mode' => 'text',
            'text_config' => [
                'font_weight' => 'normal',
                'font_size' => 12,
                'h_align' => 'left',
                'v_align' => 'top',
                'overflow' => 'trim',
            ],
        ]);

        // Without a user, the action resolves placeholder values directly
        $this->dataSourceResolver->shouldNotReceive('resolveAll');

        $result = ($this->action)($template);

        $this->assertNotEmpty($result);
    }

    public function testOverrideValuesApplied(): void
    {
        $template = $this->createTemplateWithPdf();
        $user = User::factory()->create();

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10.0,
            'y' => 10.0,
            'width' => 30.0,
            'height' => 5.0,
            'data_source_key' => 'user.email',
            'fill_mode' => 'text',
            'text_config' => [
                'font_weight' => 'normal',
                'font_size' => 12,
                'h_align' => 'left',
                'v_align' => 'top',
                'overflow' => 'trim',
            ],
        ]);

        $this->dataSourceResolver
            ->shouldReceive('resolveAll')
            ->once()
            ->andReturn(['user.email' => 'resolved@example.com']);

        $result = ($this->action)($template, $user, ['user.email' => 'override@example.com']);

        $this->assertNotEmpty($result);
        $this->assertStringStartsWith('%PDF', $result);
    }

    public function testShapeFieldRenderedWhenValueTruthy(): void
    {
        $template = $this->createTemplateWithPdf();
        $user = User::factory()->create();

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'circle',
            'x' => 50.0,
            'y' => 50.0,
            'width' => 5.0,
            'height' => 5.0,
            'data_source_key' => 'is_household_head',
            'fill_mode' => 'shape',
            'shape_config' => [
                'fill_style' => 'filled',
                'color' => '#FF0000',
            ],
        ]);

        $this->dataSourceResolver
            ->shouldReceive('resolveAll')
            ->once()
            ->andReturn(['is_household_head' => true]);

        $result = ($this->action)($template, $user);

        $this->assertNotEmpty($result);
        $this->assertStringStartsWith('%PDF', $result);
    }

    public function testShapeFieldSkippedWhenValueFalsy(): void
    {
        $template = $this->createTemplateWithPdf();
        $user = User::factory()->create();

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'circle',
            'x' => 50.0,
            'y' => 50.0,
            'width' => 5.0,
            'height' => 5.0,
            'data_source_key' => 'is_household_head',
            'fill_mode' => 'shape',
            'shape_config' => [
                'fill_style' => 'filled',
                'color' => '#FF0000',
            ],
        ]);

        $this->dataSourceResolver
            ->shouldReceive('resolveAll')
            ->once()
            ->andReturn(['is_household_head' => false]);

        $result = ($this->action)($template, $user);

        $this->assertNotEmpty($result);
        $this->assertStringStartsWith('%PDF', $result);
    }

    public function testRectangleShapeOutlinedMode(): void
    {
        $template = $this->createTemplateWithPdf();
        $user = User::factory()->create();

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10.0,
            'y' => 10.0,
            'width' => 20.0,
            'height' => 10.0,
            'data_source_key' => 'is_household_head',
            'fill_mode' => 'shape',
            'shape_config' => [
                'fill_style' => 'outlined',
                'color' => '#0000FF',
                'stroke_width' => 1.5,
            ],
        ]);

        $this->dataSourceResolver
            ->shouldReceive('resolveAll')
            ->once()
            ->andReturn(['is_household_head' => true]);

        $result = ($this->action)($template, $user);

        $this->assertNotEmpty($result);
        $this->assertStringStartsWith('%PDF', $result);
    }

    public function testTextFieldWithBoldWeight(): void
    {
        $template = $this->createTemplateWithPdf();
        $user = User::factory()->create();

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10.0,
            'y' => 10.0,
            'width' => 50.0,
            'height' => 10.0,
            'data_source_key' => 'user.name',
            'fill_mode' => 'text',
            'text_config' => [
                'font_weight' => 'bold',
                'font_size' => 14,
                'h_align' => 'center',
                'v_align' => 'middle',
                'overflow' => 'trim',
            ],
        ]);

        $this->dataSourceResolver
            ->shouldReceive('resolveAll')
            ->once()
            ->andReturn(['user.name' => 'Yamada Taro']);

        $result = ($this->action)($template, $user);

        $this->assertNotEmpty($result);
        $this->assertStringStartsWith('%PDF', $result);
    }

    public function testTextFieldWithAutoLetterSpacing(): void
    {
        $template = $this->createTemplateWithPdf();
        $user = User::factory()->create();

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10.0,
            'y' => 10.0,
            'width' => 50.0,
            'height' => 5.0,
            'data_source_key' => 'user.staff_no',
            'fill_mode' => 'text',
            'text_config' => [
                'font_weight' => 'normal',
                'font_size' => 12,
                'h_align' => 'left',
                'v_align' => 'top',
                'letter_spacing' => 'auto',
                'overflow' => 'trim',
            ],
        ]);

        $this->dataSourceResolver
            ->shouldReceive('resolveAll')
            ->once()
            ->andReturn(['user.staff_no' => '37001']);

        $result = ($this->action)($template, $user);

        $this->assertNotEmpty($result);
        $this->assertStringStartsWith('%PDF', $result);
    }

    public function testTextFieldWithWrapOverflow(): void
    {
        $template = $this->createTemplateWithPdf();
        $user = User::factory()->create();

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10.0,
            'y' => 10.0,
            'width' => 20.0,
            'height' => 15.0,
            'data_source_key' => 'user_info.address',
            'fill_mode' => 'text',
            'text_config' => [
                'font_weight' => 'normal',
                'font_size' => 10,
                'h_align' => 'left',
                'v_align' => 'top',
                'overflow' => 'wrap',
            ],
        ]);

        $this->dataSourceResolver
            ->shouldReceive('resolveAll')
            ->once()
            ->andReturn([
                'user_info.address' => 'Tokyo-to Shibuya-ku Udagawa-cho 1-2-3 Some Long Address Building Name Room 101',
            ]);

        $result = ($this->action)($template, $user);

        $this->assertNotEmpty($result);
        $this->assertStringStartsWith('%PDF', $result);
    }

    public function testTextFieldWithShrinkOverflow(): void
    {
        $template = $this->createTemplateWithPdf();
        $user = User::factory()->create();

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10.0,
            'y' => 10.0,
            'width' => 15.0,
            'height' => 5.0,
            'data_source_key' => 'user_info.address',
            'fill_mode' => 'text',
            'text_config' => [
                'font_weight' => 'normal',
                'font_size' => 20,
                'h_align' => 'left',
                'v_align' => 'top',
                'overflow' => 'shrink',
            ],
        ]);

        $this->dataSourceResolver
            ->shouldReceive('resolveAll')
            ->once()
            ->andReturn(['user_info.address' => 'A very long address that should trigger shrink-to-fit behavior']);

        $result = ($this->action)($template, $user);

        $this->assertNotEmpty($result);
        $this->assertStringStartsWith('%PDF', $result);
    }

    public function testEmptyTextFieldSkipsRendering(): void
    {
        $template = $this->createTemplateWithPdf();
        $user = User::factory()->create();

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10.0,
            'y' => 10.0,
            'width' => 30.0,
            'height' => 5.0,
            'data_source_key' => 'user.staff_no',
            'fill_mode' => 'text',
            'text_config' => [
                'font_weight' => 'normal',
                'font_size' => 12,
                'h_align' => 'left',
                'v_align' => 'top',
                'overflow' => 'trim',
            ],
        ]);

        $this->dataSourceResolver
            ->shouldReceive('resolveAll')
            ->once()
            ->andReturn(['user.staff_no' => '']);

        // Should not throw - empty values are silently skipped
        $result = ($this->action)($template, $user);

        $this->assertNotEmpty($result);
        $this->assertStringStartsWith('%PDF', $result);
    }

    public static function invertValueShapeProvider(): array
    {
        // [resolved value, invert_value flag, should shape render (no error either way)]
        return [
            'bool true without invert' => [true, false],
            'bool false without invert' => [false, false],
            'string true without invert' => ['true', false],
            'string false without invert' => ['false', false],
            'bool true with invert' => [true, true],
            'bool false with invert' => [false, true],
            'string true with invert' => ['true', true],
            'string false with invert' => ['false', true],
            'int 1 without invert' => [1, false],
            'int 0 without invert' => [0, false],
            'int 1 with invert' => [1, true],
            'int 0 with invert' => [0, true],
            'string 1 with invert' => ['1', true],
            'string 0 with invert' => ['0', true],
            'empty string with invert' => ['', true],
            'null with invert' => [null, true],
            'non-boolean string with invert' => ['some text', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invertValueShapeProvider')]
    public function testInvertValueOnShapeField(mixed $resolvedValue, bool $invertValue): void
    {
        $template = $this->createTemplateWithPdf();
        $user = User::factory()->create();

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'circle',
            'x' => 50.0,
            'y' => 50.0,
            'width' => 5.0,
            'height' => 5.0,
            'data_source_key' => 'is_era_reiwa',
            'fill_mode' => 'shape',
            'invert_value' => $invertValue,
            'shape_config' => [
                'fill_style' => 'outlined',
                'color' => '#000000',
                'stroke_width' => 0.25,
            ],
        ]);

        $this->dataSourceResolver
            ->shouldReceive('resolveAll')
            ->once()
            ->andReturn(['is_era_reiwa' => $resolvedValue]);

        $result = ($this->action)($template, $user);

        $this->assertNotEmpty($result);
        $this->assertStringStartsWith('%PDF', $result);
    }

    public function testInvertValueOnTextField(): void
    {
        $template = $this->createTemplateWithPdf();
        $user = User::factory()->create();

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10.0,
            'y' => 10.0,
            'width' => 30.0,
            'height' => 5.0,
            'data_source_key' => 'user.email',
            'fill_mode' => 'text',
            'invert_value' => true,
            'text_config' => [
                'font_weight' => 'normal',
                'font_size' => 12,
                'h_align' => 'left',
                'v_align' => 'top',
                'overflow' => 'trim',
            ],
        ]);

        // Non-boolean string should pass through unchanged even with invert
        $this->dataSourceResolver
            ->shouldReceive('resolveAll')
            ->once()
            ->andReturn(['user.email' => 'test@example.com']);

        $result = ($this->action)($template, $user);

        $this->assertNotEmpty($result);
        $this->assertStringStartsWith('%PDF', $result);
    }

    public function testMultipleFieldsOnSamePage(): void
    {
        $template = $this->createTemplateWithPdf();
        $user = User::factory()->create();

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10.0,
            'y' => 10.0,
            'width' => 30.0,
            'height' => 5.0,
            'data_source_key' => 'user.email',
            'fill_mode' => 'text',
            'text_config' => [
                'font_weight' => 'normal',
                'font_size' => 12,
                'h_align' => 'left',
                'v_align' => 'top',
                'overflow' => 'trim',
            ],
        ]);

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'rectangle',
            'x' => 10.0,
            'y' => 20.0,
            'width' => 30.0,
            'height' => 5.0,
            'data_source_key' => 'user.name',
            'fill_mode' => 'text',
            'text_config' => [
                'font_weight' => 'bold',
                'font_size' => 14,
                'h_align' => 'center',
                'v_align' => 'middle',
                'overflow' => 'trim',
            ],
        ]);

        PdfTemplateField::create([
            'pdf_template_id' => $template->id,
            'page' => 1,
            'shape' => 'circle',
            'x' => 80.0,
            'y' => 10.0,
            'width' => 5.0,
            'height' => 5.0,
            'data_source_key' => 'is_household_head',
            'fill_mode' => 'shape',
            'shape_config' => [
                'fill_style' => 'filled',
                'color' => '#00FF00',
            ],
        ]);

        $this->dataSourceResolver
            ->shouldReceive('resolveAll')
            ->once()
            ->andReturn([
                'user.email' => 'test@example.com',
                'user.name' => 'Yamada Taro',
                'is_household_head' => true,
            ]);

        $result = ($this->action)($template, $user);

        $this->assertNotEmpty($result);
        $this->assertStringStartsWith('%PDF', $result);
    }
}
