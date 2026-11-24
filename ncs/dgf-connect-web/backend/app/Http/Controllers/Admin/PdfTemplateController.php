<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PdfTemplate\IndexRequest;
use App\Http\Requests\Admin\PdfTemplate\LockRequest;
use App\Http\Requests\Admin\PdfTemplate\ReUploadRequest;
use App\Http\Requests\Admin\PdfTemplate\SaveFieldsRequest;
use App\Http\Requests\Admin\PdfTemplate\StoreRequest;
use App\Http\Requests\Admin\PdfTemplate\UpdateRequest;
use App\Models\PdfTemplate;
use App\Services\Admin\PdfTemplateService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PdfTemplateController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly PdfTemplateService $pdfTemplateService) {}

    public function index(IndexRequest $request): JsonResponse
    {
        $conditions = $request->validated();
        $templates = $this->pdfTemplateService->list($conditions);

        return ResponseHelper::successPaginated($templates);
    }

    public function store(StoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $template = $this->pdfTemplateService->create($data, $request->file('file'));

        return ResponseHelper::success($template, 201);
    }

    public function show(PdfTemplate $template): JsonResponse
    {
        $template = $this->pdfTemplateService->show($template);

        return ResponseHelper::success($template);
    }

    public function update(PdfTemplate $template, UpdateRequest $request): JsonResponse
    {
        $userId = Auth::id();
        $lockInfo = $this->pdfTemplateService->checkLock($template, $userId);

        if ($lockInfo) {
            return ResponseHelper::locked(data: $lockInfo);
        }

        $data = $request->validated();
        $template = $this->pdfTemplateService->update($template, $data);

        return ResponseHelper::success($template);
    }

    public function destroy(PdfTemplate $template): JsonResponse
    {
        $userId = Auth::id();
        $lockInfo = $this->pdfTemplateService->checkLock($template, $userId);

        if ($lockInfo) {
            return ResponseHelper::locked(data: $lockInfo);
        }

        $this->pdfTemplateService->delete($template);

        return ResponseHelper::success();
    }

    public function saveFields(PdfTemplate $template, SaveFieldsRequest $request): JsonResponse
    {
        $userId = Auth::id();
        $lockInfo = $this->pdfTemplateService->checkLock($template, $userId);

        if ($lockInfo) {
            return ResponseHelper::locked(data: $lockInfo);
        }

        $data = $request->validated();
        $template = $this->pdfTemplateService->syncFields($template, $data['fields']);

        return ResponseHelper::success($template);
    }

    public function reUpload(PdfTemplate $template, ReUploadRequest $request): JsonResponse
    {
        $userId = Auth::id();
        $lockInfo = $this->pdfTemplateService->checkLock($template, $userId);

        if ($lockInfo) {
            return ResponseHelper::locked(data: $lockInfo);
        }

        $template = $this->pdfTemplateService->reUpload($template, $request->file('file'));

        return ResponseHelper::success($template);
    }

    public function dataSources(): JsonResponse
    {
        $dataSources = $this->pdfTemplateService->getDataSources();

        return ResponseHelper::success($dataSources);
    }

    public function lock(PdfTemplate $template, LockRequest $request): JsonResponse
    {
        $userId = Auth::id();
        $userName = Auth::user()->short_name;
        $reason = $request->validated()['reason'];

        $result = $this->pdfTemplateService->acquireLock($template, $userId, $reason, $userName);

        if (is_array($result)) {
            return ResponseHelper::locked(data: $result);
        }

        $template = $this->pdfTemplateService->show($template);

        return ResponseHelper::success($template);
    }

    public function refreshLock(PdfTemplate $template): JsonResponse
    {
        $userId = Auth::id();

        $result = $this->pdfTemplateService->refreshLock($template, $userId);

        if (!$result) {
            return ResponseHelper::locked();
        }

        $template = $this->pdfTemplateService->show($template);

        return ResponseHelper::success($template);
    }

    public function unlock(PdfTemplate $template): JsonResponse
    {
        $userId = Auth::id();

        $this->pdfTemplateService->releaseLock($template, $userId);

        $template = $this->pdfTemplateService->show($template);

        return ResponseHelper::success($template);
    }

    public function file(PdfTemplate $template): StreamedResponse
    {
        return Storage::download($template->file_path, $template->file_name, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
