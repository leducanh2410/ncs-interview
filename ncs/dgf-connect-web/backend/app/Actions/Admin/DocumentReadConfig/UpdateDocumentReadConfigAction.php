<?php

namespace App\Actions\Admin\DocumentReadConfig;

use App\Actions\ActionResult;
use App\Models\DocumentReadConfirmation;
use App\Models\DocumentRequiredConfig;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class UpdateDocumentReadConfigAction
{
    public function __invoke(DocumentRequiredConfig $config, array $data): ActionResult
    {
        if ($config->workplace_id !== null) {
            abort(403, 'Config does not belong to the system scope');
        }

        if (isset($data['document_id']) && $data['document_id'] !== $config->document_id) {
            $hasConfirmations = DocumentReadConfirmation::query()
                ->where('document_required_config_id', $config->id)
                ->exists();

            if ($hasConfirmations) {
                throw ValidationException::withMessages([
                    'document_id' => ['Cannot change document_id when confirmations exist for this config'],
                ]);
            }
        }

        $updateData = array_merge(
            array_filter(
                [
                    'document_id' => $data['document_id'] ?? null,
                    'title_vi' => $data['title_vi'] ?? null,
                    'title_en' => $data['title_en'] ?? null,
                    'title_ja' => $data['title_ja'] ?? null,
                    'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : null,
                ],
                fn($value) => $value !== null
            ),
            Arr::only($data, ['title_ne', 'title_si'])
        );

        if (!empty($updateData)) {
            $config->update($updateData);
        }

        $config = $config->fresh()->load(['document', 'workplace', 'createdBy']);

        return new ActionResult(data: $config);
    }
}
