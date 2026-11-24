<?php

namespace App\Actions\Admin\DocumentReadConfig;

use App\Actions\ActionResult;
use App\Models\DocumentRequiredConfig;
use App\Models\User;

class CreateDocumentReadConfigAction
{
    public function __invoke(array $data, User $createdBy): ActionResult
    {
        $config = DocumentRequiredConfig::query()->create([
            'document_id' => $data['document_id'] ?? null,
            'workplace_id' => null,
            'title_vi' => $data['title_vi'],
            'title_en' => $data['title_en'],
            'title_ja' => $data['title_ja'],
            'title_ne' => $data['title_ne'] ?? null,
            'title_si' => $data['title_si'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $createdBy->id,
        ]);

        $config->load(['document', 'workplace', 'createdBy']);

        return new ActionResult(data: $config, status: 201);
    }
}
