<?php

namespace App\Actions\User\Document;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\Document;
use App\Services\User\DocumentService;

class GetDocumentDetailAction
{
    private DocumentService $documentService;

    public function __construct(DocumentService $documentService)
    {
        $this->documentService = $documentService;
    }

    public function __invoke(int $userId, Document $document): ActionResult
    {
        if (!$this->documentService->canUserAccessDocument($userId, $document)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $document->load('attachments');

        return new ActionResult(data: $document);
    }
}
