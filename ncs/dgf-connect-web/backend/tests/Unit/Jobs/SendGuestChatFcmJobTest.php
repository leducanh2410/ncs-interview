<?php

namespace Tests\Unit\Jobs;

use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Jobs\SendGuestChatFcmJob;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Mockery;
use Tests\TestCase;

class SendGuestChatFcmJobTest extends TestCase
{
    public function testUsesEmployeeFirebaseProject(): void
    {
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->once()->andReturnNull();

        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->with('app')->once()->andReturn($messaging);

        $this->makeJob()->handle($resolver);
    }

    public function testSendsWithApnsBadgeZero(): void
    {
        $capturedMessage = null;

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (CloudMessage $message, array $tokens) use (&$capturedMessage) {
                $capturedMessage = $message;

                return true;
            })
            ->andReturnNull();

        $this->makeJob()->handle($this->makeResolver($messaging));

        $this->assertNotNull($capturedMessage);
        $array = $capturedMessage->jsonSerialize();

        $this->assertSame(0, $array['apns']['payload']['aps']['badge'] ?? null);
    }

    public function testLogsErrorAndDoesNotRethrowWhenFcmFails(): void
    {
        Log::shouldReceive('error')->once();

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->andThrow(new \RuntimeException('FCM error'));

        $this->makeJob()->handle($this->makeResolver($messaging));

        $this->assertTrue(true); // no exception propagated
    }

    public function testSkipsWhenFirebaseMessagingUnavailable(): void
    {
        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->with('app')->andReturn(null);

        $this->makeJob()->handle($resolver);

        $this->assertTrue(true); // no exception, no call
    }

    private function makeJob(array $overrides = []): SendGuestChatFcmJob
    {
        return new SendGuestChatFcmJob(
            fcmToken: $overrides['fcmToken'] ?? 'test-fcm-token',
            conversationId: $overrides['conversationId'] ?? 1,
            title: $overrides['title'] ?? 'New message',
            body: $overrides['body'] ?? 'You have a new message',
            data: $overrides['data'] ?? ['conversation_id' => '1']
        );
    }

    private function makeResolver(?Messaging $messaging): ResolveFirebaseMessagingAction
    {
        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->with('app')->andReturn($messaging);

        return $resolver;
    }
}
