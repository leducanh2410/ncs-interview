<?php

namespace Tests\Unit\Listeners;

use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Events\ShiftUserApproved;
use App\Listeners\CheckWorkplaceDocumentRequirementsListener;
use App\Models\UserDevice;
use App\Services\DocumentReadConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Mockery;
use Tests\Concerns\BuildsDocumentReadFixtures;
use Tests\TestCase;

class CheckWorkplaceDocumentRequirementsListenerTest extends TestCase
{
    use BuildsDocumentReadFixtures;
    use RefreshDatabase;

    private function firebaseResolver(?Messaging $messaging): ResolveFirebaseMessagingAction
    {
        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->andReturn($messaging);

        return $resolver;
    }

    public function testSetsFlagAndNotifiesOnFirstApprovedShiftWithActiveConfigs()
    {
        $creator = $this->createUser();
        $user = $this->createUser(['paper_status' => 'approved']);
        $workplace = $this->createWorkplace();
        $shiftUser = $this->assignApprovedShift($user, $workplace, creator: $creator);
        $this->createConfig($creator, workplace: $workplace);
        UserDevice::query()->create([
            'user_id' => $user->id,
            'device_id' => 'device-1',
            'name' => 'Primary device',
            'platform' => 'ios',
            'fcm_token' => 'token-1',
        ]);

        $service = Mockery::mock(DocumentReadConfirmationService::class);
        $service
            ->shouldReceive('setPendingFlag')
            ->once()
            ->withArgs(fn($passedUser, $value) => $passedUser->is($user) && $value === true);
        $service->shouldReceive('serializePendingConfigs')->once()->andReturn([]);

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->once();

        $listener = new CheckWorkplaceDocumentRequirementsListener($service);
        $listener->handle(
            new ShiftUserApproved(now(), $shiftUser->fresh(['user', 'shift'])),
            $this->firebaseResolver($messaging)
        );
    }

    public function testNotificationUsesRecipientLocaleInsteadOfAppLocale()
    {
        $creator = $this->createUser();
        $user = $this->createUser(['paper_status' => 'approved', 'settings' => ['lang' => 'ne']]);
        $workplace = $this->createWorkplace();
        $shiftUser = $this->assignApprovedShift($user, $workplace, creator: $creator);
        $this->createConfig($creator, workplace: $workplace);
        UserDevice::query()->create([
            'user_id' => $user->id,
            'device_id' => 'device-1',
            'name' => 'Primary device',
            'platform' => 'ios',
            'fcm_token' => 'token-1',
        ]);

        $service = Mockery::mock(DocumentReadConfirmationService::class);
        $service->shouldReceive('setPendingFlag')->once();
        $service->shouldReceive('serializePendingConfigs')->once()->andReturn([]);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (CloudMessage $message) {
                $notification = $message->jsonSerialize()['notification'];

                return $notification['title'] === __('lang.document_read_required.title', [], 'ne') &&
                    $notification['body'] === __('lang.document_read_required.content', [], 'ne');
            });

        $listener = new CheckWorkplaceDocumentRequirementsListener($service);
        $listener->handle(
            new ShiftUserApproved(now(), $shiftUser->fresh(['user', 'shift'])),
            $this->firebaseResolver($messaging)
        );
    }

    public function testDoesNotSetFlagOnSecondApprovedShiftAtSameWorkplace()
    {
        $creator = $this->createUser();
        $user = $this->createUser(['paper_status' => 'approved']);
        $workplace = $this->createWorkplace();

        $this->assignApprovedShift($user, $workplace, now()->subDay(), $creator);
        $shiftUser = $this->assignApprovedShift($user, $workplace, now(), $creator);
        $this->createConfig($creator, workplace: $workplace);

        $service = Mockery::mock(DocumentReadConfirmationService::class);
        $service->shouldNotReceive('setPendingFlag');

        $listener = new CheckWorkplaceDocumentRequirementsListener($service);
        $listener->handle(new ShiftUserApproved(now(), $shiftUser->fresh(['user', 'shift'])));
    }

    public function testDoesNotSetFlagWhenNoActiveWorkplaceConfigsExist()
    {
        $creator = $this->createUser();
        $user = $this->createUser(['paper_status' => 'approved']);
        $workplace = $this->createWorkplace();
        $shiftUser = $this->assignApprovedShift($user, $workplace, creator: $creator);
        $this->createConfig($creator, workplace: $workplace, attributes: ['is_active' => false]);

        $service = Mockery::mock(DocumentReadConfirmationService::class);
        $service->shouldNotReceive('setPendingFlag');

        $listener = new CheckWorkplaceDocumentRequirementsListener($service);
        $listener->handle(new ShiftUserApproved(now(), $shiftUser->fresh(['user', 'shift'])));
    }

    public function testDoesNotSetFlagWhenUserIsNotApproved()
    {
        $creator = $this->createUser();
        $user = $this->createUser(['paper_status' => 'pending']);
        $workplace = $this->createWorkplace();
        $shiftUser = $this->assignApprovedShift($user, $workplace, creator: $creator);
        $this->createConfig($creator, workplace: $workplace);

        $service = Mockery::mock(DocumentReadConfirmationService::class);
        $service->shouldNotReceive('setPendingFlag');

        $listener = new CheckWorkplaceDocumentRequirementsListener($service);
        $listener->handle(new ShiftUserApproved(now(), $shiftUser->fresh(['user', 'shift'])));
    }
}
