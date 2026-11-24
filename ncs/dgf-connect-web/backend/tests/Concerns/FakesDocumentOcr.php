<?php

namespace Tests\Concerns;

use App\Jobs\ExtractDocumentStepOcrJob;
use App\Models\UserPaperStep;
use App\Services\External\OpenRouterOcrService;
use App\Services\Ocr\OcrResultCacheService;
use Illuminate\Support\Facades\Http;

/**
 * Answers the OCR provider and runs the queued extraction the way production does.
 *
 * Confirming a step is only possible once its reading finished, so a test that walks the
 * document flow has to let the job run instead of confirming against a run that is still
 * pending — otherwise it asserts against a state the system never hands a real client.
 */
trait FakesDocumentOcr
{
    /**
     * Fields the provider answers with, per OCR group, on top of the defaults below.
     *
     * Read when the request is made rather than when the stub is registered, because
     * Http::fake() merges into the stubs a setUp already installed instead of replacing
     * them — a test staging a document that disagrees sets this before it uploads.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $ocrFieldOverrides = [];

    /**
     * Provider stub answering each OCR group with the fields that group extracts.
     *
     * Returned as a stub map rather than a catch-all so a test can keep its own stubs for
     * the other services it talks to.
     *
     * @param array<string, array<string, mixed>> $overrides extra/replaced fields per group
     * @return array<string, mixed>
     */
    protected function documentOcrProviderStub(array $overrides = []): array
    {
        return [
            'openrouter.ai/*' => function ($request) use ($overrides) {
                $group = data_get($request->data(), 'response_format.json_schema.name');

                $fields = array_merge(
                    $this->ocrFieldsFor($group),
                    $overrides[$group] ?? [],
                    $this->ocrFieldOverrides[$group] ?? []
                );

                return Http::response([
                    'model' => 'test-model',
                    'choices' => [['message' => ['content' => json_encode($fields)]]],
                ]);
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function ocrFieldsFor(?string $group): array
    {
        return match ($group) {
            OpenRouterOcrService::GROUP_RESIDENCE_CARD => [
                'name' => 'NGUYEN VAN A',
                'last_name_kana' => 'グエン',
                'first_name_kana' => 'バン エー',
                'birthday' => '2000-01-01',
                'gender' => 'male',
                'nationality' => 'vietnam',
                'address' => '東京都渋谷区1-1-1',
                'prefecture' => '東京都',
                'residence_number' => 'AB12345678CD',
                'residence_expiry_date' => '2030-01-01',
                'residence_status_raw' => '留学',
            ],
            OpenRouterOcrService::GROUP_RESIDENCE_CARD_SCAN => [
                'name' => 'NGUYEN VAN A',
                'birthday' => '2000-01-01',
                'nationality' => 'vietnam',
                'residence_number' => 'AB12345678CD',
                'residence_expiry_date' => '2030-01-01',
                'residence_status_raw' => '留学',
            ],
            OpenRouterOcrService::GROUP_PASSPORT => [
                'passport_number' => 'C1234567',
                'passport_expiry_date' => '2031-05-05',
                'name' => 'NGUYEN VAN A',
                'birthday' => '2000-01-01',
            ],
            OpenRouterOcrService::GROUP_STUDENT_CARD => [
                'student_card_expiry_date' => '2027-03-31',
                'name' => 'NGUYEN VAN A',
                'school_name' => '東京日本語学校',
            ],
            default => [],
        };
    }

    /**
     * Run every still-pending extraction job of the step, in the order the queue would.
     */
    protected function runQueuedOcrFor(UserPaperStep $step): UserPaperStep
    {
        foreach ($step->refresh()->ocrResults as $result) {
            (new ExtractDocumentStepOcrJob($result->id, $step->id))->handle(app(OcrResultCacheService::class));
        }

        return $step->refresh();
    }
}
