<?php

namespace Tests\Unit\Jobs;

use App\Actions\Common\Firebase\NormalizeFirebaseMessageDataAction;
use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Actions\Manage\Notification\ResolveRecruitmentRecipientsAction;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Jobs\PushNotificationToUser;
use App\Models\ChatParticipant;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\Shift;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\Workplace;
use App\Services\Manage\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Mockery;
use Tests\Concerns\BuildsRecruitmentFixtures;
use Tests\TestCase;

/**
 * Covers the recruitment branch added to PushNotificationToUser: resolving recipients and
 * pushing to them individually, as distinct from the pre-existing topic-broadcast and
 * single-user paths (already covered by PushNotificationToUserTest, left untouched here).
 */
class PushNotificationToUserRecruitmentTest extends TestCase
{
    use BuildsRecruitmentFixtures;
    use RefreshDatabase;

    private Workplace $workplace;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workplace = $this->createWorkplaceAt();
        $this->shift = $this->createShiftAt($this->workplace);
    }

    public function testResolvesRecipientsAndPushesToEachIndividually(): void
    {
        $recipient = $this->createEmployeeAt($this->workplace);
        UserDevice::query()->create([
            'user_id' => $recipient->id,
            'device_id' => 'device-1',
            'name' => 'Primary device',
            'platform' => 'android',
            'fcm_token' => 'recipient-token',
        ]);
        $notification = $this->createRecruitmentNotification($this->shift);

        $capturedTokens = null;
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (CloudMessage $message, array $tokens) use (&$capturedTokens) {
                $capturedTokens = $tokens;

                return true;
            })
            ->andReturn(MulticastSendReport::withItems([]));

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        $notification->refresh();
        $this->assertSame(NotificationStatus::SENT->value, $notification->status);
        $this->assertSame(1, $notification->recipients_resolved_count);
        $this->assertSame(['recipient-token'], $capturedTokens);
        $this->assertTrue(
            NotificationRecipient::query()
                ->where('notification_id', $notification->id)
                ->where('user_id', $recipient->id)
                ->exists()
        );
    }

    public function testRecipientWithoutADeviceIsStillCountedButNoPushIsAttempted(): void
    {
        $recipient = $this->createEmployeeAt($this->workplace);
        $notification = $this->createRecruitmentNotification($this->shift);

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldNotReceive('sendMulticast');

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        $notification->refresh();
        $this->assertSame(NotificationStatus::SENT->value, $notification->status);
        $this->assertSame(1, $notification->recipients_resolved_count);
        $this->assertTrue(
            NotificationRecipient::query()
                ->where('notification_id', $notification->id)
                ->where('user_id', $recipient->id)
                ->exists(),
            'A recipient with no device still sees the notification in-app and must still be recorded'
        );
    }

    public function testEmptyRecipientListMarksSentWithZeroAndNeverBroadcasts(): void
    {
        // Nobody is attached to this workplace at all.
        $notification = $this->createRecruitmentNotification($this->shift);

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldNotReceive('sendMulticast');
        $messaging->shouldNotReceive('send');

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        $notification->refresh();
        $this->assertSame(NotificationStatus::SENT->value, $notification->status);
        $this->assertSame(0, $notification->recipients_resolved_count);
        $this->assertSame(0, NotificationRecipient::query()->where('notification_id', $notification->id)->count());
    }

    public function testAPushFailureForOneRecipientDoesNotAbortTheRemainingChunkOrFailTheNotification(): void
    {
        $failing = $this->createEmployeeAt($this->workplace);
        $succeeding = $this->createEmployeeAt($this->workplace);
        UserDevice::query()->create([
            'user_id' => $failing->id,
            'device_id' => 'failing-device',
            'name' => 'Failing device',
            'platform' => 'android',
            'fcm_token' => 'failing-token',
        ]);
        UserDevice::query()->create([
            'user_id' => $succeeding->id,
            'device_id' => 'succeeding-device',
            'name' => 'Succeeding device',
            'platform' => 'android',
            'fcm_token' => 'succeeding-token',
        ]);
        $notification = $this->createRecruitmentNotification($this->shift);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->twice()
            ->andReturnUsing(function (CloudMessage $message, array $tokens) {
                if (in_array('failing-token', $tokens, true)) {
                    throw new \RuntimeException('Simulated Firebase failure');
                }

                return MulticastSendReport::withItems([]);
            });

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        $notification->refresh();
        // Committing the recipient rows is what SENT means here, not that every push
        // succeeded — unlike the single-user path, in-app delivery does not depend on FCM.
        $this->assertSame(NotificationStatus::SENT->value, $notification->status);
        $this->assertSame(2, $notification->recipients_resolved_count);
        $this->assertSame(
            2,
            NotificationRecipient::query()->where('notification_id', $notification->id)->count(),
            'Both recipients remain recorded even though one push attempt failed'
        );
    }

    public function testAReportContainingFailedTokensIsLoggedButStillLeavesTheNotificationSent(): void
    {
        // Distinct from the thrown-exception case above: Firebase accepts the request and
        // answers with a per-token report. Nothing throws, so only hasFailures() reveals that
        // a device was unreachable, and the notification must still count as delivered
        // because in-app visibility comes from the recipient rows, not from FCM.
        $recipient = $this->createEmployeeAt($this->workplace);
        UserDevice::query()->create([
            'user_id' => $recipient->id,
            'device_id' => 'stale-device',
            'name' => 'Stale device',
            'platform' => 'android',
            'fcm_token' => 'stale-token',
        ]);
        $notification = $this->createRecruitmentNotification($this->shift);

        $report = MulticastSendReport::withItems([
            SendReport::failure(
                MessageTarget::with(MessageTarget::TOKEN, 'stale-token'),
                NotFound::becauseTokenNotFound('stale-token')
            ),
        ]);

        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')
            ->once()
            ->with('Push recruitment notification to recipient had failed tokens', Mockery::type('array'));

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->once()->andReturn($report);

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        $notification->refresh();
        $this->assertSame(NotificationStatus::SENT->value, $notification->status);
        $this->assertSame(1, $notification->recipients_resolved_count);
        $this->assertSame(
            1,
            NotificationRecipient::query()->where('notification_id', $notification->id)->count(),
            'An unreachable device does not remove the employee from the recipient list'
        );
    }

    public function testResolutionFailureMarksFailedAndPersistsNoRecipients(): void
    {
        $this->createEmployeeAt($this->workplace);
        $notification = $this->createRecruitmentNotification($this->shift);

        $failingResolver = Mockery::mock(ResolveRecruitmentRecipientsAction::class);
        $failingResolver->shouldReceive('__invoke')->andThrow(new \RuntimeException('Simulated resolution failure'));
        $this->instance(ResolveRecruitmentRecipientsAction::class, $failingResolver);

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldNotReceive('sendMulticast');

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        $notification->refresh();
        $this->assertSame(NotificationStatus::FAILED->value, $notification->status);
        $this->assertNull($notification->recipients_resolved_count);
        $this->assertSame(0, NotificationRecipient::query()->where('notification_id', $notification->id)->count());
    }

    public function testTokensAreChunkedToFirebasesMulticastCeiling(): void
    {
        $recipient = $this->createEmployeeAt($this->workplace);
        // 501 devices for a single recipient forces two sendMulticast calls (500 + 1),
        // exercising the chunking rather than merely asserting a constant exists.
        for ($i = 0; $i < 501; $i++) {
            UserDevice::query()->create([
                'user_id' => $recipient->id,
                'device_id' => "device-{$i}",
                'name' => "Device {$i}",
                'platform' => 'android',
                'fcm_token' => "token-{$i}",
            ]);
        }
        $notification = $this->createRecruitmentNotification($this->shift);

        $chunkSizes = [];
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->twice()
            ->andReturnUsing(function (CloudMessage $message, array $tokens) use (&$chunkSizes) {
                $chunkSizes[] = count($tokens);

                return MulticastSendReport::withItems([]);
            });

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        sort($chunkSizes);
        $this->assertSame([1, 500], $chunkSizes);
    }

    public function testIosRecipientReceivesTheirOwnBadgeCount(): void
    {
        $recipient = $this->createEmployeeAt($this->workplace);
        UserDevice::query()->create([
            'user_id' => $recipient->id,
            'device_id' => 'ios-device',
            'name' => 'iOS device',
            'platform' => 'iOS 26.5',
            'fcm_token' => 'ios-token',
        ]);
        // One unread chat conversation on top of the notification being delivered -> badge 2,
        // mirroring how the single-user path's badge test is set up.
        ChatParticipant::factory()->create(['user_id' => $recipient->id, 'unread_count' => 1]);
        $notification = $this->createRecruitmentNotification($this->shift);

        $capturedMessage = null;
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (CloudMessage $message, array $tokens) use (&$capturedMessage) {
                $capturedMessage = $message;

                return true;
            })
            ->andReturn(MulticastSendReport::withItems([]));

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        $this->assertNotNull($capturedMessage);
        $array = $capturedMessage->jsonSerialize();
        $this->assertSame(2, $array['apns']['payload']['aps']['badge'] ?? null);
    }

    public function testAppendsHotBonusLineInRecipientsLanguageWhenPresent(): void
    {
        $recipient = $this->createEmployeeAt($this->workplace);
        $recipient->update(['settings' => ['lang' => 'en']]);
        UserDevice::query()->create([
            'user_id' => $recipient->id,
            'device_id' => 'device-1',
            'name' => 'Primary device',
            'platform' => 'android',
            'fcm_token' => 'recipient-token',
        ]);
        $notification = $this->createRecruitmentNotification($this->shift, ['hot_bonus_amount' => 500000]);
        $notification->translations()->create([
            'lang' => 'en',
            'title' => 'Urgent staff needed (EN)',
            'content' => 'Please help cover this shift (EN)',
        ]);

        $capturedMessage = null;
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (CloudMessage $message) use (&$capturedMessage) {
                $capturedMessage = $message;

                return true;
            })
            ->andReturn(MulticastSendReport::withItems([]));

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        $this->assertNotNull($capturedMessage);
        $body = $capturedMessage->jsonSerialize()['notification']['body'] ?? null;
        $this->assertSame("Please help cover this shift (EN)\nOn-the-spot bonus: 500,000", $body);
    }

    public function testDoesNotAppendHotBonusLineWhenAbsent(): void
    {
        $recipient = $this->createEmployeeAt($this->workplace);
        UserDevice::query()->create([
            'user_id' => $recipient->id,
            'device_id' => 'device-1',
            'name' => 'Primary device',
            'platform' => 'android',
            'fcm_token' => 'recipient-token',
        ]);
        $notification = $this->createRecruitmentNotification($this->shift);

        $capturedMessage = null;
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (CloudMessage $message) use (&$capturedMessage) {
                $capturedMessage = $message;

                return true;
            })
            ->andReturn(MulticastSendReport::withItems([]));

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        $this->assertNotNull($capturedMessage);
        $body = $capturedMessage->jsonSerialize()['notification']['body'] ?? null;
        $this->assertSame('Please help cover this shift', $body);
    }

    /**
     * The recipient's saved language ('en') has no matching translation on this notification
     * (only 'ja' exists), so content falls back to the base row. The bonus line must follow
     * that same fallback — the system default locale (vi) — rather than the recipient's
     * unmatched 'en' preference, or the two lines would end up in different languages.
     */
    public function testHotBonusLineFollowsContentLanguageNotUnmatchedUserPreference(): void
    {
        $recipient = $this->createEmployeeAt($this->workplace);
        $recipient->update(['settings' => ['lang' => 'en']]);
        UserDevice::query()->create([
            'user_id' => $recipient->id,
            'device_id' => 'device-1',
            'name' => 'Primary device',
            'platform' => 'android',
            'fcm_token' => 'recipient-token',
        ]);
        $notification = $this->createRecruitmentNotification($this->shift, ['hot_bonus_amount' => 500000]);
        $notification->translations()->create([
            'lang' => 'ja',
            'title' => 'Urgent staff needed (JA)',
            'content' => 'Please help cover this shift (JA)',
        ]);

        $capturedMessage = null;
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (CloudMessage $message) use (&$capturedMessage) {
                $capturedMessage = $message;

                return true;
            })
            ->andReturn(MulticastSendReport::withItems([]));

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        $this->assertNotNull($capturedMessage);
        $body = $capturedMessage->jsonSerialize()['notification']['body'] ?? null;
        // Base content (not the 'ja' translation), bonus line in 'vi' (system default), not 'en'.
        $this->assertSame("Please help cover this shift\nTiền thưởng nóng: 500,000", $body);
    }

    /**
     * Reaches the "recipient's language has no genuine translation" state through the real
     * creation flow (NotificationService::createOrUpdateNotification), not a hand-picked
     * fixture. The service always creates a row for every non-default locale — including
     * 'en' — even when the admin only wrote the base Vietnamese text, so that row exists
     * and translations->has('en') is true, but it is filler (is_fallback), not a genuine
     * translation. No English translation was provided to copy from either, so the filler
     * is a copy of the base text itself — the content and the hot bonus line both end up in
     * the base language, same as if there were no row at all.
     */
    public function testHotBonusLineMatchesFallbackContentWhenAdminOnlyWroteTheBaseLanguage(): void
    {
        $recipient = $this->createEmployeeAt($this->workplace);
        $recipient->update(['settings' => ['lang' => 'en']]);
        UserDevice::query()->create([
            'user_id' => $recipient->id,
            'device_id' => 'device-1',
            'name' => 'Primary device',
            'platform' => 'android',
            'fcm_token' => 'recipient-token',
        ]);

        $result = app(NotificationService::class)->createOrUpdateNotification(null, [
            'type' => NotificationType::RECRUITMENT->value,
            'title' => 'Urgent staff needed',
            'content' => 'Please help cover this shift',
            'is_manually_scheduled' => false,
            'shift_id' => $this->shift->id,
            'hot_bonus_amount' => 500000,
        ]);
        $notification = $result['data'];
        $englishRow = $notification->translations()->where('lang', 'en')->first();
        $this->assertNotNull($englishRow, 'The service must have created a filler en row');
        $this->assertTrue($englishRow->is_fallback);
        $this->assertSame(config('app.base_locale'), $englishRow->fallback_source_lang);

        $capturedMessage = null;
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (CloudMessage $message) use (&$capturedMessage) {
                $capturedMessage = $message;

                return true;
            })
            ->andReturn(MulticastSendReport::withItems([]));

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        $this->assertNotNull($capturedMessage);
        $body = $capturedMessage->jsonSerialize()['notification']['body'] ?? null;
        $this->assertSame("Please help cover this shift\nTiền thưởng nóng: 500,000", $body);
    }

    /**
     * The admin wrote the base Vietnamese text and an explicit English translation, but left
     * Nepali/Sinhala blank. NotificationService still creates 'ne'/'si' rows so those
     * recipients get something more readable than the raw base text — copying the English
     * translation in as filler (is_fallback, fallback_source_lang = 'en'). A Nepali-speaking
     * recipient must therefore see that English fallback content, with the bonus line in
     * English to match — not discarded back to the base language, and not mismatched against
     * a Nepali bonus line for content that isn't actually in Nepali.
     */
    public function testFallbackContentAndItsBonusLineBothUseTheContentsRealSourceLanguage(): void
    {
        $recipient = $this->createEmployeeAt($this->workplace);
        $recipient->update(['settings' => ['lang' => 'ne']]);
        UserDevice::query()->create([
            'user_id' => $recipient->id,
            'device_id' => 'device-1',
            'name' => 'Primary device',
            'platform' => 'android',
            'fcm_token' => 'recipient-token',
        ]);

        $result = app(NotificationService::class)->createOrUpdateNotification(null, [
            'type' => NotificationType::RECRUITMENT->value,
            'title' => 'Urgent staff needed',
            'content' => 'Please help cover this shift',
            'title_en' => 'Urgent staff needed (EN)',
            'content_en' => 'Please help cover this shift (EN)',
            'is_manually_scheduled' => false,
            'shift_id' => $this->shift->id,
            'hot_bonus_amount' => 500000,
        ]);
        $notification = $result['data'];
        $nepaliRow = $notification->translations()->where('lang', 'ne')->first();
        $this->assertNotNull($nepaliRow);
        $this->assertTrue($nepaliRow->is_fallback);
        $this->assertSame('en', $nepaliRow->fallback_source_lang);

        $capturedMessage = null;
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (CloudMessage $message) use (&$capturedMessage) {
                $capturedMessage = $message;

                return true;
            })
            ->andReturn(MulticastSendReport::withItems([]));

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        $this->assertNotNull($capturedMessage);
        $body = $capturedMessage->jsonSerialize()['notification']['body'] ?? null;
        $this->assertSame("Please help cover this shift (EN)\nOn-the-spot bonus: 500,000", $body);
    }

    public function testTopicBroadcastPathIsUntouchedForNonRecruitmentTypes(): void
    {
        // user_id null but type is NOT recruitment: must still go through the pre-existing
        // topic path rather than being intercepted by the new branch.
        $notification = Notification::query()->create([
            'type' => NotificationType::NORMAL->value,
            'title' => 'Announcement',
            'content' => 'Announcement',
            'status' => NotificationStatus::PENDING->value,
            'is_manually_scheduled' => false,
            'scheduled_at' => now()->subMinute(),
            'user_id' => null,
            'data' => [],
        ]);

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('send')->atLeast()->once();

        (new PushNotificationToUser())->handle($this->firebaseResolver($messaging), $this->passthroughNormalizer());

        $notification->refresh();
        $this->assertSame(NotificationStatus::SENT->value, $notification->status);
        // The topic path has no concept of a resolved recipient count.
        $this->assertNull($notification->recipients_resolved_count);
    }

    private function firebaseResolver(?Messaging $messaging): ResolveFirebaseMessagingAction
    {
        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->andReturn($messaging);

        return $resolver;
    }

    /**
     * FCM data payloads must be string => string; stand in for the real normalizer with a
     * simple cast rather than the mocked-return-unchanged shortcut, which would pass ints
     * straight through and break CloudMessage::withData.
     */
    private function passthroughNormalizer(): NormalizeFirebaseMessageDataAction
    {
        $normalizer = Mockery::mock(NormalizeFirebaseMessageDataAction::class);
        $normalizer
            ->shouldReceive('execute')
            ->andReturnUsing(fn(array $data) => array_map(static fn($value) => (string) ($value ?? ''), $data));

        return $normalizer;
    }
}
