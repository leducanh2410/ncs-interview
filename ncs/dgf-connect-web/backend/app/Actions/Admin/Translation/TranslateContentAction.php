<?php

namespace App\Actions\Admin\Translation;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Services\External\OpenRouterTranslationService;
use Illuminate\Support\Facades\Log;
use Throwable;

class TranslateContentAction
{
    public const ERROR_TRANSLATION_PROVIDER = 'TRANSLATION_PROVIDER_ERROR';

    public function __construct(private readonly OpenRouterTranslationService $translationService) {}

    public function __invoke(array $data): ActionResult
    {
        $items = [];
        foreach ($data['items'] as $item) {
            $items[$item['key']] = $item['text'];
        }

        try {
            $translations = $this->translationService->translate(
                $items,
                $data['source_locale'],
                $data['target_locales'],
                $data['context']
            );
        } catch (Throwable $e) {
            Log::error('Content translation failed', [
                'context' => $data['context'],
                'error' => $e->getMessage(),
            ]);

            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => self::ERROR_TRANSLATION_PROVIDER]
            );
        }

        return new ActionResult(data: ['translations' => $translations]);
    }
}
