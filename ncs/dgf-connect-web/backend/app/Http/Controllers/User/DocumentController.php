<?php

namespace App\Http\Controllers\User;

use App\Actions\User\Document\GetDocumentDetailAction;
use App\Actions\User\Document\GetUserDocumentsAction;
use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class DocumentController extends Controller
{
    public function index(GetUserDocumentsAction $action): JsonResponse
    {
        return $action(Auth::id())->toResponse(true);
    }

    public function show(Document $document, GetDocumentDetailAction $action): JsonResponse
    {
        return $action(Auth::id(), $document)->toResponse();
    }
}
