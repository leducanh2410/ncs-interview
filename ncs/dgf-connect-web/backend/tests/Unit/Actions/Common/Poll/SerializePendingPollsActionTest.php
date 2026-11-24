<?php

namespace Tests\Unit\Actions\Common\Poll;

use App\Actions\Common\Poll\SerializePendingPollsAction;
use App\Enums\Nationality;
use App\Enums\PollType;
use App\Models\Poll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SerializePendingPollsActionTest extends TestCase
{
    use RefreshDatabase;

    public function testSerializesFeedbackPollWithMultilingualLabels(): void
    {
        $poll = Poll::factory()->create([
            'type' => PollType::Feedback,
            'title_vi' => 'Khảo sát',
            'title_en' => 'Survey',
            'title_ja' => '調査',
            'description_vi' => 'Mô tả tiếng Việt',
            'description_en' => 'English description',
            'description_ja' => '日本語の説明',
            'is_required' => true,
            'is_multiple' => true,
            'max_choice' => 2,
            'expires_at' => now()->addDays(7),
            'options' => [
                ['id' => '01JABC', 'label_vi' => 'Tốt', 'label_en' => 'Good', 'label_ja' => '良い'],
                ['id' => '01JDEF', 'label_vi' => 'Xấu', 'label_en' => 'Bad', 'label_ja' => '悪い'],
            ],
        ]);

        $action = new SerializePendingPollsAction();
        $result = $action->__invoke(new Collection([$poll]));

        $this->assertCount(1, $result);
        $this->assertEquals($poll->id, $result[0]['id']);
        $this->assertEquals('feedback', $result[0]['type']);
        $this->assertEquals('Survey', $result[0]['title_en']);
        $this->assertEquals('English description', $result[0]['description_en']);
        $this->assertTrue($result[0]['is_required']);
        $this->assertTrue($result[0]['is_multiple']);
        $this->assertEquals(2, $result[0]['max_choice']);
        $this->assertCount(2, $result[0]['options']);
        $this->assertEquals('01JABC', $result[0]['options'][0]['id']);
        $this->assertEquals('Good', $result[0]['options'][0]['label_en']);
    }

    public function testSerializesVotePollWithCandidateNames(): void
    {
        // Pin nationality: UserFactory attaches a UserInfo with a random
        // nationality, and Sri Lanka triggers name-shortening (short_name),
        // which would make this test flaky since it asserts against the
        // full, unshortened name. Must update through the loaded relation
        // (->info->update()), not the relation query (->info()->update()):
        // the latter is a bulk query-builder update that bypasses Eloquent
        // model events, so UserInfoShortNameObserver would never re-sync
        // short_name away from a randomly-rolled Sri Lanka value.
        $user1 = User::factory()->create(['first_name' => 'Nguyen', 'last_name' => 'Van A']);
        $user1->info->update(['nationality' => Nationality::Vietnam->value]);
        $user2 = User::factory()->create(['first_name' => 'Tran', 'last_name' => 'Thi B']);
        $user2->info->update(['nationality' => Nationality::Vietnam->value]);

        $poll = Poll::factory()->create([
            'type' => PollType::Vote,
            'title_vi' => 'Bầu chọn',
            'title_en' => 'Vote',
            'title_ja' => '投票',
            'is_required' => false,
            'is_multiple' => false,
            'max_choice' => 1,
            'expires_at' => null,
            'options' => [$user1->id, $user2->id],
        ]);

        $action = new SerializePendingPollsAction();
        $result = $action->__invoke(new Collection([$poll]));

        $this->assertCount(1, $result);
        $this->assertEquals('vote', $result[0]['type']);
        $this->assertCount(2, $result[0]['options']);
        $this->assertEquals($user1->id, $result[0]['options'][0]['id']);
        $this->assertEquals($user1->name, $result[0]['options'][0]['name']);
        $this->assertEquals($user2->id, $result[0]['options'][1]['id']);
        $this->assertEquals($user2->name, $result[0]['options'][1]['name']);
    }

    public function testReturnsEmptyArrayForEmptyCollection(): void
    {
        $action = new SerializePendingPollsAction();
        $result = $action->__invoke(new Collection([]));

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testFallsBackToEnglishForNepaliAndSinhalaWhenNotSet(): void
    {
        $poll = Poll::factory()->create([
            'type' => PollType::Feedback,
            'title_en' => 'Survey',
            'title_ne' => null,
            'title_si' => null,
            'description_en' => 'English description',
            'description_ne' => null,
            'description_si' => null,
        ]);

        $action = new SerializePendingPollsAction();
        $result = $action->__invoke(new Collection([$poll]));

        $this->assertEquals('Survey', $result[0]['title_ne']);
        $this->assertEquals('Survey', $result[0]['title_si']);
        $this->assertEquals('English description', $result[0]['description_ne']);
        $this->assertEquals('English description', $result[0]['description_si']);
    }

    public function testFallsBackToEnglishForNepaliAndSinhalaFeedbackOptionLabelsWhenNotSet(): void
    {
        $poll = Poll::factory()->create([
            'type' => PollType::Feedback,
            'options' => [['id' => '01JABC', 'label_vi' => 'Tốt', 'label_en' => 'Good', 'label_ja' => '良い']],
        ]);

        $action = new SerializePendingPollsAction();
        $result = $action->__invoke(new Collection([$poll]));

        $this->assertEquals('Good', $result[0]['options'][0]['label_ne']);
        $this->assertEquals('Good', $result[0]['options'][0]['label_si']);
    }

    public function testUsesNepaliAndSinhalaFeedbackOptionLabelsWhenSet(): void
    {
        $poll = Poll::factory()->create([
            'type' => PollType::Feedback,
            'options' => [
                [
                    'id' => '01JABC',
                    'label_vi' => 'Tốt',
                    'label_en' => 'Good',
                    'label_ja' => '良い',
                    'label_ne' => 'राम्रो',
                    'label_si' => 'හොඳයි',
                ],
            ],
        ]);

        $action = new SerializePendingPollsAction();
        $result = $action->__invoke(new Collection([$poll]));

        $this->assertEquals('राम्रो', $result[0]['options'][0]['label_ne']);
        $this->assertEquals('හොඳයි', $result[0]['options'][0]['label_si']);
    }

    public function testUsesNepaliAndSinhalaValuesWhenSet(): void
    {
        $poll = Poll::factory()->create([
            'type' => PollType::Feedback,
            'title_en' => 'Survey',
            'title_ne' => 'सर्वेक्षण',
            'title_si' => 'මත විමසුම',
            'description_en' => 'English description',
            'description_ne' => 'नेपाली विवरण',
            'description_si' => 'සිංහල විස්තරය',
        ]);

        $action = new SerializePendingPollsAction();
        $result = $action->__invoke(new Collection([$poll]));

        $this->assertEquals('सर्वेक्षण', $result[0]['title_ne']);
        $this->assertEquals('මත විමසුම', $result[0]['title_si']);
        $this->assertEquals('नेपाली विवरण', $result[0]['description_ne']);
        $this->assertEquals('සිංහල විස්තරය', $result[0]['description_si']);
    }
}
