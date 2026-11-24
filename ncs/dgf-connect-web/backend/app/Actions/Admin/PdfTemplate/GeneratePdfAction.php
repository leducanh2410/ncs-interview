<?php

namespace App\Actions\Admin\PdfTemplate;

use App\Enums\PdfTemplateFieldFillMode;
use App\Enums\PdfTemplateFieldShape;
use App\Models\PdfTemplate;
use App\Models\PdfTemplateField;
use App\Models\User;
use App\Services\Admin\PdfTemplate\DataSourceResolver;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Tcpdf\Fpdi;

class GeneratePdfAction
{
    private const FONT_NAME = 'notosansjp';

    private const FONT_NAME_BOLD = 'notosansjpb';

    private const FONT_DIR = 'storage/fonts/';

    private const MIN_FONT_SIZE = 4;

    // NotoSansJP font Ascent from descriptor (1160) exceeds the 1000-unit em-square.
    // TCPDF positions baseline at Y + (Ascent/1000)*FontSize, but the canvas uses the
    // em-square top (1000 units). Subtract the excess to align PDF text with canvas.
    private const FONT_ASCENT_EXCESS = (1160 - 1000) / 1000;

    public function __construct(private readonly DataSourceResolver $dataSourceResolver) {}

    public function __invoke(PdfTemplate $template, ?User $user = null, array $overrides = []): string
    {
        $template->loadMissing('fields');

        $resolvedValues = $this->resolveFieldValues($template, $user, $overrides);

        $pdf = $this->createPdfInstance($template);

        for ($pageNum = 1; $pageNum <= $template->page_count; $pageNum++) {
            $templatePageId = $pdf->importPage($pageNum);
            $size = $pdf->getTemplateSize($templatePageId);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useImportedPage($templatePageId);

            $pageFields = $template->fields->where('page', $pageNum);

            foreach ($pageFields as $field) {
                $value = $resolvedValues[$field->data_source_key] ?? '';
                $this->renderField($pdf, $field, $value, $size['width'], $size['height']);
            }
        }

        return $pdf->Output('', 'S');
    }

    private function resolveFieldValues(PdfTemplate $template, ?User $user, array $overrides): array
    {
        $dataSourceKeys = $template->fields->pluck('data_source_key')->unique()->toArray();
        $resolved = [];

        if ($user) {
            $resolved = $this->dataSourceResolver->resolveAll($dataSourceKeys, $user);
        } else {
            foreach ($template->fields as $field) {
                $resolved[$field->data_source_key] = $this->getPlaceholderValue($field);
            }
        }

        return array_merge($resolved, $overrides);
    }

    private function getPlaceholderValue(PdfTemplateField $field): string
    {
        if ($field->placeholder !== null && $field->placeholder !== '') {
            return $field->placeholder;
        }

        $sources = config('pdf_data_sources.sources', []);
        $source = collect($sources)->firstWhere('key', $field->data_source_key);

        return $source['placeholder'] ?? '';
    }

    private function createPdfInstance(PdfTemplate $template): Fpdi
    {
        $pdf = new Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetMargins(0, 0, 0);
        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->setCellHeightRatio(1.0);

        $fontDir = base_path(self::FONT_DIR);
        $pdf->setFontSubsetting(true);
        $pdf->AddFont(self::FONT_NAME, '', $fontDir . self::FONT_NAME . '.php');
        $pdf->AddFont(self::FONT_NAME_BOLD, '', $fontDir . self::FONT_NAME_BOLD . '.php');

        $filePath = Storage::path($template->file_path);
        $pdf->setSourceFile($filePath);

        return $pdf;
    }

    private function renderField(
        Fpdi $pdf,
        PdfTemplateField $field,
        mixed $value,
        float $pageWidth,
        float $pageHeight
    ): void {
        if ($field->invert_value) {
            $value = $this->invertValue($value);
        }

        if ($field->fill_mode === PdfTemplateFieldFillMode::Text) {
            $this->renderTextField($pdf, $field, (string) $value, $pageWidth, $pageHeight);
        } elseif ($field->fill_mode === PdfTemplateFieldFillMode::Shape) {
            $this->renderShapeField($pdf, $field, $value, $pageWidth, $pageHeight);
        }
    }

    private function invertValue(mixed $value): mixed
    {
        if ($value === true || $value === 'true' || $value === 1 || $value === '1') {
            return false;
        }

        if ($value === false || $value === 'false' || $value === 0 || $value === '0') {
            return true;
        }

        return $value;
    }

    private function toAbsolute(float $percent, float $dimension): float
    {
        return ($percent / 100.0) * $dimension;
    }

    // --- Text Fill Mode ---

    private function renderTextField(
        Fpdi $pdf,
        PdfTemplateField $field,
        string $value,
        float $pageWidth,
        float $pageHeight
    ): void {
        if ($value === '') {
            return;
        }

        $x = $this->toAbsolute($field->x, $pageWidth);
        $y = $this->toAbsolute($field->y, $pageHeight);
        $w = $this->toAbsolute($field->width, $pageWidth);
        $h = $this->toAbsolute($field->height, $pageHeight);

        $config = $field->text_config ?? [];
        $fontWeight = $config['font_weight'] ?? 'normal';
        $fontSize = (float) ($config['font_size'] ?? 10);
        $hAlign = $config['h_align'] ?? 'left';
        $vAlign = $config['v_align'] ?? 'top';
        $letterSpacing = $config['letter_spacing'] ?? null;
        $overflow = $config['overflow'] ?? 'trim';

        $fontName = $fontWeight === 'bold' ? self::FONT_NAME_BOLD : self::FONT_NAME;
        $pdf->SetFont($fontName, '', $fontSize);
        $pdf->SetTextColor(0, 0, 0);

        if ($letterSpacing === 'auto') {
            $this->renderAutoSpacedText($pdf, $value, $x, $y, $w, $h, $fontSize, $fontName, $vAlign);

            return;
        }

        if (is_numeric($letterSpacing) && (float) $letterSpacing > 0) {
            $this->renderFixedSpacedText(
                $pdf,
                $value,
                $x,
                $y,
                $w,
                $h,
                $fontSize,
                $fontName,
                (float) $letterSpacing,
                $vAlign
            );

            return;
        }

        if ($overflow === 'shrink') {
            $fontSize = $this->calculateShrinkFontSize($pdf, $value, $w, $h, $fontSize, $fontName);
            $pdf->SetFont($fontName, '', $fontSize);
        }

        $tcpdfAlign = $this->getTcpdfAlign($hAlign);
        $adjustedY = $this->calculateVerticalPosition(
            $pdf,
            $value,
            $y,
            $h,
            $fontSize,
            $vAlign,
            $overflow,
            $w,
            $fontName
        );

        if ($overflow === 'wrap') {
            $pdf->SetXY($x, $adjustedY);
            $pdf->MultiCell(
                $w,
                $h - ($adjustedY - $y),
                $value,
                0,
                $tcpdfAlign,
                false,
                1,
                $x,
                $adjustedY,
                true,
                0,
                false,
                true,
                $h - ($adjustedY - $y),
                'T',
                false
            );
        } else {
            $pdf->SetXY($x, $adjustedY);
            $cellH = $pdf->getStringHeight($w, $value, false, true, '', 1);
            $pdf->Cell($w, min($cellH, $h), $value, 0, 0, $tcpdfAlign, false, '', 1, false, 'T', 'T');
        }
    }

    private function renderAutoSpacedText(
        Fpdi $pdf,
        string $value,
        float $x,
        float $y,
        float $w,
        float $h,
        float $fontSize,
        string $fontName,
        string $vAlign
    ): void {
        $chars = mb_str_split($value);
        $charCount = count($chars);

        if ($charCount <= 1) {
            $adjustedY = $this->calculateVerticalPositionSimple($pdf, $y, $h, $fontSize, $vAlign);
            $pdf->SetXY($x, $adjustedY);
            $pdf->Cell($w, $fontSize * 0.3528, $value, 0, 0, 'C', false, '', 0, false, 'T', 'T');

            return;
        }

        $totalCharWidth = 0;
        foreach ($chars as $char) {
            $totalCharWidth += $pdf->GetStringWidth($char);
        }

        $spacing = ($w - $totalCharWidth) / ($charCount - 1);
        $adjustedY = $this->calculateVerticalPositionSimple($pdf, $y, $h, $fontSize, $vAlign);

        $currentX = $x;
        foreach ($chars as $char) {
            $charWidth = $pdf->GetStringWidth($char);
            $pdf->SetXY($currentX, $adjustedY);
            $pdf->Cell($charWidth, $fontSize * 0.3528, $char, 0, 0, 'L', false, '', 0, false, 'T', 'T');
            $currentX += $charWidth + $spacing;
        }
    }

    private function renderFixedSpacedText(
        Fpdi $pdf,
        string $value,
        float $x,
        float $y,
        float $w,
        float $h,
        float $fontSize,
        string $fontName,
        float $letterSpacing,
        string $vAlign
    ): void {
        $chars = mb_str_split($value);
        $adjustedY = $this->calculateVerticalPositionSimple($pdf, $y, $h, $fontSize, $vAlign);

        $currentX = $x;
        foreach ($chars as $char) {
            $charWidth = $pdf->GetStringWidth($char);
            $pdf->SetXY($currentX, $adjustedY);
            $pdf->Cell($charWidth, $fontSize * 0.3528, $char, 0, 0, 'L', false, '', 0, false, 'T', 'T');
            $currentX += $charWidth + $letterSpacing;

            if ($currentX >= $x + $w) {
                break;
            }
        }
    }

    private function calculateShrinkFontSize(
        Fpdi $pdf,
        string $value,
        float $w,
        float $h,
        float $fontSize,
        string $fontName
    ): float {
        $currentSize = $fontSize;

        while ($currentSize > self::MIN_FONT_SIZE) {
            $pdf->SetFont($fontName, '', $currentSize);
            $textWidth = $pdf->GetStringWidth($value);
            $textHeight = $pdf->getStringHeight($w, $value, false, true, '', 1);

            if ($textWidth <= $w && $textHeight <= $h) {
                return $currentSize;
            }

            $currentSize -= 0.5;
        }

        return self::MIN_FONT_SIZE;
    }

    private function getAscentCompensation(float $fontSize): float
    {
        return self::FONT_ASCENT_EXCESS * $fontSize * 0.3528;
    }

    private function calculateVerticalPosition(
        Fpdi $pdf,
        string $value,
        float $y,
        float $h,
        float $fontSize,
        string $vAlign,
        string $overflow,
        float $w,
        string $fontName
    ): float {
        $compensation = $this->getAscentCompensation($fontSize);

        if ($vAlign === 'top') {
            return $y - $compensation;
        }

        $textHeight = $pdf->getStringHeight($w, $value, false, true, '', 1);

        if ($overflow === 'wrap') {
            $textHeight = min($textHeight, $h);
        } else {
            $textHeight = min($textHeight, $h);
        }

        return match ($vAlign) {
            'middle' => $y + ($h - $textHeight) / 2 - $compensation,
            'bottom' => $y + $h - $textHeight - $compensation,
            default => $y - $compensation,
        };
    }

    private function calculateVerticalPositionSimple(
        Fpdi $pdf,
        float $y,
        float $h,
        float $fontSize,
        string $vAlign
    ): float {
        $lineHeight = $fontSize * 0.3528;
        $compensation = $this->getAscentCompensation($fontSize);

        return match ($vAlign) {
            'middle' => $y + ($h - $lineHeight) / 2 - $compensation,
            'bottom' => $y + $h - $lineHeight - $compensation,
            default => $y - $compensation,
        };
    }

    private function getTcpdfAlign(string $hAlign): string
    {
        return match ($hAlign) {
            'center' => 'C',
            'right' => 'R',
            default => 'L',
        };
    }

    // --- Shape Fill Mode ---

    private function renderShapeField(
        Fpdi $pdf,
        PdfTemplateField $field,
        mixed $value,
        float $pageWidth,
        float $pageHeight
    ): void {
        if ($value === false || $value === 'false' || $value === '0' || $value === '' || $value === null) {
            return;
        }

        $x = $this->toAbsolute($field->x, $pageWidth);
        $y = $this->toAbsolute($field->y, $pageHeight);
        $w = $this->toAbsolute($field->width, $pageWidth);
        $h = $this->toAbsolute($field->height, $pageHeight);

        $config = $field->shape_config ?? [];
        $fillStyle = $config['fill_style'] ?? 'filled';
        $color = $config['color'] ?? '#000000';
        $strokeWidth = (float) ($config['stroke_width'] ?? 0.25);

        $rgb = $this->hexToRgb($color);

        if ($fillStyle === 'filled') {
            $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
            $style = 'F';
        } else {
            $pdf->SetDrawColor($rgb[0], $rgb[1], $rgb[2]);
            $pdf->SetLineWidth($strokeWidth);
            $style = 'D';
        }

        if ($field->shape === PdfTemplateFieldShape::Circle) {
            $cx = $x + $w / 2;
            $cy = $y + $h / 2;
            $rx = $w / 2;
            $ry = $h / 2;
            $pdf->Ellipse($cx, $cy, $rx, $ry, 0, 0, 360, $style);
        } else {
            $pdf->Rect($x, $y, $w, $h, $style);
        }
    }

    private function hexToRgb(string $hex): array
    {
        $hex = mb_ltrim($hex, '#');

        if (mb_strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [hexdec(mb_substr($hex, 0, 2)), hexdec(mb_substr($hex, 2, 2)), hexdec(mb_substr($hex, 4, 2))];
    }
}
