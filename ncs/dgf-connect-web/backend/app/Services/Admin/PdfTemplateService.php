<?php

namespace App\Services\Admin;

use App\Helpers\UrlHelper;
use App\Models\PdfTemplate;
use App\Models\PdfTemplateField;
use App\Services\Admin\PdfTemplate\DataSourceResolver;
use App\Services\Admin\PdfTemplate\PdfTemplateLockService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

class PdfTemplateService
{
    private const FILE_PATH_PREFIX = 'pdf-templates/';

    public function __construct(
        private readonly PdfTemplateLockService $lockService,
        private readonly DataSourceResolver $dataSourceResolver
    ) {}

    public function list(array $conditions): LengthAwarePaginator
    {
        $query = PdfTemplate::query()->whereNull('deleted_at');

        if (!empty($conditions['name'])) {
            $query->where('name', 'like', '%' . $conditions['name'] . '%');
        }

        $pageSize = $conditions['page_size'] ?? 15;

        $paginator = $query->orderByDesc('created_at')->paginate($pageSize);

        $paginator->through(function ($template) {
            $template->lock_info = $this->lockService->get($template->id);

            return $template;
        });

        return $paginator;
    }

    public function create(array $data, UploadedFile $file): PdfTemplate
    {
        return DB::transaction(function () use ($data, $file) {
            $fileName = $file->getClientOriginalName();
            $filePath = $file->store(self::FILE_PATH_PREFIX);
            $pageCount = $this->getPdfPageCount(Storage::path($filePath));

            return PdfTemplate::query()->create([
                'name' => $data['name'],
                'file_path' => $filePath,
                'file_name' => $fileName,
                'page_count' => $pageCount,
            ]);
        });
    }

    public function show(PdfTemplate $template): PdfTemplate
    {
        $template->load(['fields']);
        $template->file_url = UrlHelper::getStorageUrl($template->file_path);
        $template->lock_info = $this->lockService->get($template->id);

        return $template;
    }

    public function update(PdfTemplate $template, array $data): PdfTemplate
    {
        $template->update([
            'name' => $data['name'],
        ]);

        return $template;
    }

    public function delete(PdfTemplate $template): void
    {
        $template->delete();
    }

    public function syncFields(PdfTemplate $template, array $fields): PdfTemplate
    {
        DB::transaction(function () use ($template, $fields) {
            $template->fields()->delete();

            $fieldRecords = collect($fields)
                ->map(function ($field) use ($template) {
                    return [
                        'pdf_template_id' => $template->id,
                        'page' => $field['page'],
                        'shape' => $field['shape'],
                        'x' => $field['x'],
                        'y' => $field['y'],
                        'width' => $field['width'],
                        'height' => $field['height'],
                        'data_source_key' => $field['data_source_key'],
                        'placeholder' => $field['placeholder'] ?? null,
                        'invert_value' => $field['invert_value'] ?? false,
                        'fill_mode' => $field['fill_mode'],
                        'text_config' => isset($field['text_config']) ? json_encode($field['text_config']) : null,
                        'shape_config' => isset($field['shape_config']) ? json_encode($field['shape_config']) : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                })
                ->toArray();

            PdfTemplateField::query()->insert($fieldRecords);
        });

        return $this->show($template);
    }

    public function reUpload(PdfTemplate $template, UploadedFile $file): PdfTemplate
    {
        return DB::transaction(function () use ($template, $file) {
            Storage::delete($template->file_path);

            $fileName = $file->getClientOriginalName();
            $filePath = $file->store(self::FILE_PATH_PREFIX);
            $pageCount = $this->getPdfPageCount(Storage::path($filePath));

            $template->update([
                'file_path' => $filePath,
                'file_name' => $fileName,
                'page_count' => $pageCount,
            ]);

            return $template;
        });
    }

    public function getDataSources(): array
    {
        return $this->dataSourceResolver->getAll();
    }

    public function checkLock(PdfTemplate $template, int $userId): ?array
    {
        $lockInfo = $this->lockService->get($template->id);

        if (!$lockInfo) {
            return null;
        }

        if ($lockInfo['user_id'] === $userId) {
            return null;
        }

        return $lockInfo;
    }

    public function acquireLock(PdfTemplate $template, int $userId, string $reason, string $userName): array|bool
    {
        $existingLock = $this->lockService->get($template->id);

        if ($existingLock && $existingLock['user_id'] !== $userId) {
            return $existingLock;
        }

        if ($existingLock && $existingLock['user_id'] === $userId) {
            $this->lockService->refresh($template->id, $userId);

            return true;
        }

        $this->lockService->acquire($template->id, $userId, $reason, $userName);

        return true;
    }

    public function releaseLock(PdfTemplate $template, int $userId): bool
    {
        return $this->lockService->release($template->id, $userId);
    }

    public function refreshLock(PdfTemplate $template, int $userId): array|bool
    {
        $existingLock = $this->lockService->get($template->id);

        if (!$existingLock || $existingLock['user_id'] !== $userId) {
            return false;
        }

        $this->lockService->refresh($template->id, $userId);

        return true;
    }

    private function getPdfPageCount(string $filePath): int
    {
        try {
            $fpdi = new Fpdi();

            return $fpdi->setSourceFile($filePath);
        } catch (\Exception $e) {
            return 1;
        }
    }
}
