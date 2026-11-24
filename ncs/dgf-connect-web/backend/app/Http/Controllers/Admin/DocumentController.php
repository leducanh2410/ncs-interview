<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Document\CreateRequest;
use App\Http\Requests\Admin\Document\IndexRequest;
use App\Http\Requests\Admin\Document\SearchRequest;
use App\Http\Requests\Admin\Document\UpdateRequest;
use App\Models\Document;
use App\Models\User;
use App\Services\Admin\DocumentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class DocumentController extends Controller
{
    use AuthorizesRequests;

    private readonly DocumentService $documentService;

    public function __construct(DocumentService $documentService)
    {
        $this->documentService = $documentService;
    }

    public function index(IndexRequest $request): JsonResponse
    {
        $conditions = $request->validated();
        $documents = $this->documentService->listDocuments($conditions);

        return ResponseHelper::successPaginated($documents);
    }

    public function search(SearchRequest $request): JsonResponse
    {
        $conditions = $request->validated();
        if (isset($conditions['key'])) {
            if (is_array($conditions['key'])) {
                $conditions['ids'] = $conditions['key'];
            } else {
                $conditions['id'] = $conditions['key'];
            }
            unset($conditions['key']);
        } else {
            $conditions['query'] = $conditions['q'];
            unset($conditions['q']);
        }

        return ResponseHelper::success($this->documentService->searchDocuments($conditions));
    }

    public function show(Document $document): JsonResponse
    {
        return ResponseHelper::success($this->documentService->show($document));
    }

    public function create(CreateRequest $request): Document
    {
        $data = $request->validated();

        return $this->documentService->create($data);
    }

    public function update(Document $document, UpdateRequest $request): Document
    {
        $data = $request->validated();

        return $this->documentService->update($document, $data);
    }

    public function delete(Document $document): JsonResponse
    {
        return ResponseHelper::success($this->documentService->delete($document));
    }

    public function indexForUser(IndexRequest $request): JsonResponse
    {
        $user = $request->user();

        $documents = $this->documentService->listAccessibleDocuments(
            user: $user,
            pageSize: $request->get('page_size', 10)
        );

        return ResponseHelper::successPaginated($documents);
    }

    public function showForUser(int $documentId): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $document = $this->documentService->getAccessibleDocument($user, $documentId);
        if (!$document) {
            return ResponseHelper::notFound();
        }

        return ResponseHelper::success($document);
    }
}
