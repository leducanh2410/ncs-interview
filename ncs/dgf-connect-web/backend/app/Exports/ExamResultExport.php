<?php

namespace App\Exports;

use App\Helpers\CsvHelper;
use App\Models\ExamResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ExamResultExport implements FromCollection, WithCustomCsvSettings, WithHeadings, WithMapping
{
    private readonly Collection $data;

    private readonly string $locale;

    public function __construct(Collection $data, string $locale)
    {
        $this->data = $data;
        $this->locale = $locale;
    }

    public function collection(): Collection
    {
        return $this->data;
    }

    public function getCsvSettings(): array
    {
        return ['use_bom' => true];
    }

    public function headings(): array
    {
        return [
            __('exam_result.csv.exam_name', [], $this->locale),
            __('exam_result.csv.status', [], $this->locale),
            __('exam_result.csv.completed_at', [], $this->locale),
            __('exam_result.csv.occurrence', [], $this->locale),
            __('exam_result.csv.workplace', [], $this->locale),
        ];
    }

    /**
     * @param ExamResult $examResult
     */
    public function map($examResult): array
    {
        $statusKey = "exam_result.status.{$examResult->status}";
        $status = __($statusKey, [], $this->locale);

        return [
            CsvHelper::escapeFormula($examResult->exam?->translation($this->locale)),
            CsvHelper::escapeFormula($status === $statusKey ? $examResult->status : $status),
            $examResult->completed_at ? Carbon::parse($examResult->completed_at)->format('Y-m-d H:i') : null,
            $examResult->occurrence,
            CsvHelper::escapeFormula($examResult->workplace?->name),
        ];
    }
}
