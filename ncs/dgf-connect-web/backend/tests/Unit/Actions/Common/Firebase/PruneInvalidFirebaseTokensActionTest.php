<?php

namespace Tests\Unit\Actions\Common\Firebase;

use App\Actions\Common\Firebase\PruneInvalidFirebaseTokensAction;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Tests\TestCase;

class PruneInvalidFirebaseTokensActionTest extends TestCase
{
    use RefreshDatabase;

    public function testClearsUnknownTokenWhenSameBatchHasSuccessfulSend(): void
    {
        $validDevice = $this->createDevice('valid-token');
        $unknownDevice = $this->createDevice('unknown-token');
        $report = MulticastSendReport::withItems([
            SendReport::success(MessageTarget::with(MessageTarget::TOKEN, 'valid-token'), []),
            SendReport::failure(
                MessageTarget::with(MessageTarget::TOKEN, 'unknown-token'),
                NotFound::becauseTokenNotFound('unknown-token')
            ),
        ]);

        $count = app(PruneInvalidFirebaseTokensAction::class)->execute($report);

        $this->assertSame(1, $count);
        $this->assertSame('valid-token', $validDevice->fresh()->fcm_token);
        $this->assertNull($unknownDevice->fresh()->fcm_token);
    }

    public function testDoesNotClearTokensWhenWholeBatchFails(): void
    {
        $device = $this->createDevice('unknown-token');
        $report = MulticastSendReport::withItems([
            SendReport::failure(
                MessageTarget::with(MessageTarget::TOKEN, 'unknown-token'),
                NotFound::becauseTokenNotFound('unknown-token')
            ),
        ]);

        $count = app(PruneInvalidFirebaseTokensAction::class)->execute($report);

        $this->assertSame(0, $count);
        $this->assertSame('unknown-token', $device->fresh()->fcm_token);
    }

    private function createDevice(string $token): UserDevice
    {
        $user = User::factory()->create();

        return UserDevice::query()->create([
            'user_id' => $user->id,
            'device_id' => 'device-' . $user->id,
            'name' => 'Mobile device',
            'platform' => 'Android 35',
            'fcm_token' => $token,
        ]);
    }
}
