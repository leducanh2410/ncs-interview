<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\ConfirmDocumentReadRequest;
use App\Models\DocumentRequiredConfig;
use App\Services\DocumentReadConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class DocumentReadConfirmationController extends Controller
{
    private readonly DocumentReadConfirmationService $confirmationService;

    public function __construct(DocumentReadConfirmationService $confirmationService)
    {
        $this->confirmationService = $confirmationService;
    }

    public function confirm(DocumentRequiredConfig $config, ConfirmDocumentReadRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $confirmation = $this->confirmationService->confirm(
            $user,
            $config->id,
            $request->ip(),
            $request->input('device_info', [])
        );

        $user->refresh();

        return response()->json([
            'data' => [
                'confirmed' => true,
                'has_pending_documents' => $user->has_pending_documents,
            ],
        ]);
    }
}
