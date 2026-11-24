<?php

namespace Tests\Feature\Api\User;

use App\Enums\OcrResultStatus;
use App\Enums\ResidenceStatus;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperStepStatus;
use App\Enums\UserPaperType;
use App\Models\User;
use App\Models\UserPaperItem;
use App\Models\UserPaperOcrResult;
use App\Models\UserPaperStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocumentReadFixtures;
use Tests\Concerns\ResetsUserDocumentInfo;
use Tests\TestCase;

class DocumentStepOverviewTest extends TestCase
{
    use BuildsDocumentReadFixtures;
    use RefreshDatabase;
    use ResetsUserDocumentInfo;

    public function testOverviewListsAllStepsWithRequiredFlags(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user, 'api')->getJson('/user/document-steps');

        $response->assertStatus(200);
        $response->assertJsonPath('data.paper_status', UserPaperStatus::Pending->value);
        $response->assertJsonCount(6, 'data.steps');

        $steps = collect($response->json('data.steps'))->keyBy('key');
        $this->assertTrue($steps[UserPaperStepKey::ResidenceCard->value]['required']);
        $this->assertTrue($steps[UserPaperStepKey::Passport->value]['required']);
        $this->assertTrue($steps[UserPaperStepKey::Portrait->value]['required']);
        $this->assertFalse($steps[UserPaperStepKey::StudentCard->value]['required']);
        $this->assertFalse($steps[UserPaperStepKey::WorkPermit->value]['required']);
        $this->assertFalse($steps[UserPaperStepKey::Other->value]['required']);
        $this->assertNull($steps[UserPaperStepKey::ResidenceCard->value]['status']);
        $this->assertFalse($steps[UserPaperStepKey::ResidenceCard->value]['needs_reupload']);
    }

    public function testStudentCardBecomesRequiredForStudyAbroad(): void
    {
        $user = $this->makeUser();
        $user->info->update([
            'residence_expiry_date_1' => '2028-01-01',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/user/document-steps');

        $steps = collect($response->json('data.steps'))->keyBy('key');
        $this->assertTrue($steps[UserPaperStepKey::StudentCard->value]['required']);
    }

    public function testStepNeedingReuploadIsPresentedAsOpen(): void
    {
        $user = $this->makeUser();
        UserPaperStep::query()->create([
            'user_paper_id' => $user->paper->id,
            'step_key' => UserPaperStepKey::Passport->value,
            'status' => UserPaperStepStatus::NeedsReupload->value,
            'ocr_run_count' => 0,
        ]);

        $overview = $this->actingAs($user, 'api')->getJson('/user/document-steps');
        $steps = collect($overview->json('data.steps'))->keyBy('key');
        $this->assertNull($steps[UserPaperStepKey::Passport->value]['status']);
        $this->assertTrue($steps[UserPaperStepKey::Passport->value]['needs_reupload']);

        $this->actingAs($user, 'api')
            ->getJson('/user/document-steps/passport')
            ->assertStatus(200)
            ->assertJsonPath('data.status', null)
            ->assertJsonPath('data.needs_reupload', true)
            ->assertJsonPath('data.ocr_result_id', null);
    }

    public function testStepDetailReturnsOcrResultItemsAndCurrentValues(): void
    {
        $user = $this->makeUser();
        $user->info->update([
            'phone' => '08012345678',
            'residence_number_1' => 'AB12345678CD',
            'residence_expiry_date_1' => '2027-01-31',
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
        ]);

        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $user->paper->id,
            'type' => UserPaperType::ForeignerCardFront->value,
            'approved_at' => null,
            'rejected_at' => null,
        ]);
        $ocrResult = UserPaperOcrResult::query()->create([
            'ocr_group' => 'residence_card',
            'item_ids' => [$item->id],
            'cache_key' => str_repeat('a', 64),
            'model' => 'test/model',
            'status' => OcrResultStatus::Completed->value,
            'fields' => ['residence_number' => 'AB12345678CD'],
            'warnings' => [],
            'source' => 'employee',
            'completed_at' => now(),
        ]);
        UserPaperStep::query()->create([
            'user_paper_id' => $user->paper->id,
            'step_key' => UserPaperStepKey::ResidenceCard->value,
            'status' => UserPaperStepStatus::OcrReady->value,
            'ocr_result_id' => $ocrResult->id,
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/user/document-steps/residence_card');

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', UserPaperStepStatus::OcrReady->value);
        $response->assertJsonPath('data.ocr.status', OcrResultStatus::Completed->value);
        $response->assertJsonPath('data.ocr.fields.residence_number', 'AB12345678CD');
        $response->assertJsonPath('data.items.0.id', $item->id);
        $response->assertJsonPath('data.current_values.phone', '08012345678');
        $response->assertJsonPath('data.current_values.residence_card.residence_number', 'AB12345678CD');

        $ocrResult->update(['invalidated_at' => now()]);

        $this->actingAs($user, 'api')
            ->getJson('/user/document-steps/residence_card')
            ->assertStatus(200)
            ->assertJsonPath('data.ocr_result_id', null)
            ->assertJsonPath('data.ocr', null);

        $overview = $this->actingAs($user, 'api')->getJson('/user/document-steps');
        $step = collect($overview->json('data.steps'))->firstWhere('key', UserPaperStepKey::ResidenceCard->value);
        $this->assertNull($step['ocr_result_id']);
        $this->assertNull($step['ocr']);
    }

    private function makeUser(): User
    {
        $user = $this->createUser(['paper_status' => UserPaperStatus::Pending->value]);
        $this->resetUserDocumentInfo($user);
        $user->paper()->create();

        return $user->fresh(['info', 'paper']);
    }
}
