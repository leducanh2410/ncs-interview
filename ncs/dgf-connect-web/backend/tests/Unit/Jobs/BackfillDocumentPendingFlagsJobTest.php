<?php

namespace Tests\Unit\Jobs;

use App\Actions\Common\Firebase\NormalizeFirebaseMessageDataAction;
use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Jobs\BackfillDocumentPendingFlagsJob;
use App\Models\DocumentReadConfirmation;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Mockery;
use Tests\Concerns\BuildsDocumentReadFixtures;
use Tests\TestCase;

class BackfillDocumentPendingFlagsJobTest extends TestCase
{
    use BuildsDocumentReadFixtures;
    use RefreshDatabase;

    private function firebaseResolver(?Messaging $messaging = null): ResolveFirebaseMessagingAction
    {
        $resolver = Mockery::mock(ResolveFirebaseMessagingAction::class);
        $resolver->shouldReceive('execute')->andReturn($messaging);

        return $resolver;
    }

    public function testSetsFlagForAllPaperApprovedUsersWithoutConfirmationForSystemConfig()
    {
        $creator = $this->createUser();
        $config = $this->createConfig($creator);
        $eligibleUser = $this->createUser(['paper_status' => 'approved']);
        $confirmedUser = $this->createUser(['paper_status' => 'approved']);
        $ineligibleUser = $this->createUser(['paper_status' => 'registered']);

        DocumentReadConfirmation::query()->create([
            'user_id' => $confirmedUser->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'Phone'],
        ]);

        $job = new BackfillDocumentPendingFlagsJob($config->id);
        $job->handle(null, $this->firebaseResolver());

        $this->assertTrue($eligibleUser->fresh()->has_pending_documents);
        $this->assertFalse($confirmedUser->fresh()->has_pending_documents);
        $this->assertFalse($ineligibleUser->fresh()->has_pending_documents);
    }

    public function testSetsFlagForUsersWithApprovedShiftAtWorkplaceWithoutConfirmation()
    {
        $creator = $this->createUser();
        $workplace = $this->createWorkplace();
        $otherWorkplace = $this->createWorkplace();
        $config = $this->createConfig($creator, workplace: $workplace);
        $eligibleUser = $this->createUser(['paper_status' => 'approved']);
        $otherWorkplaceUser = $this->createUser(['paper_status' => 'approved']);
        $confirmedUser = $this->createUser(['paper_status' => 'approved']);

        $this->assignApprovedShift($eligibleUser, $workplace, creator: $creator);
        $this->assignApprovedShift($otherWorkplaceUser, $otherWorkplace, creator: $creator);
        $this->assignApprovedShift($confirmedUser, $workplace, creator: $creator);

        DocumentReadConfirmation::query()->create([
            'user_id' => $confirmedUser->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'Phone'],
        ]);

        $job = new BackfillDocumentPendingFlagsJob($config->id);
        $job->handle(null, $this->firebaseResolver());

        $this->assertTrue($eligibleUser->fresh()->has_pending_documents);
        $this->assertFalse($otherWorkplaceUser->fresh()->has_pending_documents);
        $this->assertFalse($confirmedUser->fresh()->has_pending_documents);
    }

    public function testDoesNotSetWorkplaceFlagForNonApprovedUsers()
    {
        $creator = $this->createUser();
        $workplace = $this->createWorkplace();
        $config = $this->createConfig($creator, workplace: $workplace);
        $eligibleUser = $this->createUser(['paper_status' => 'pending']);

        $this->assignApprovedShift($eligibleUser, $workplace, creator: $creator);

        $job = new BackfillDocumentPendingFlagsJob($config->id);
        $job->handle(null, $this->firebaseResolver());

        $this->assertFalse($eligibleUser->fresh()->has_pending_documents);
    }

    public function testProcessesLargeSystemBackfillInChunksWithoutUpdatingConfirmedUsers()
    {
        $creator = $this->createUser();
        $config = $this->createConfig($creator);

        $eligibleUsers = collect();
        for ($index = 0; $index < 101; $index++) {
            $eligibleUsers->push(
                $this->createUser([
                    'paper_status' => 'approved',
                    'email' => "eligible{$index}@example.com",
                ])
            );
        }

        $confirmedUser = $this->createUser([
            'paper_status' => 'approved',
            'email' => 'confirmed@example.com',
        ]);
        DocumentReadConfirmation::query()->create([
            'user_id' => $confirmedUser->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'Phone'],
        ]);

        $job = new BackfillDocumentPendingFlagsJob($config->id);
        $job->handle(null, $this->firebaseResolver());

        foreach ($eligibleUsers as $eligibleUser) {
            $this->assertTrue($eligibleUser->fresh()->has_pending_documents);
        }

        $this->assertFalse($confirmedUser->fresh()->has_pending_documents);
    }

    public function testNormalizesNotificationDataOnceForAllUsers()
    {
        $creator = $this->createUser();
        $config = $this->createConfig($creator);
        $firstUser = $this->createUser(['paper_status' => 'approved']);
        $secondUser = $this->createUser(['paper_status' => 'approved']);

        foreach ([$firstUser, $secondUser] as $index => $user) {
            UserDevice::query()->create([
                'user_id' => $user->id,
                'device_id' => "device-{$index}",
                'name' => 'Android device',
                'platform' => 'android',
                'fcm_token' => "token-{$index}",
            ]);
        }

        $normalizedData = [
            'type' => 'document_read_required',
            'pending_documents' => 'normalized-pending-documents',
        ];
        $normalizer = Mockery::mock(NormalizeFirebaseMessageDataAction::class);
        $normalizer
            ->shouldReceive('execute')
            ->once()
            ->with(
                Mockery::on(
                    static fn(array $data) => $data['type'] === 'document_read_required' &&
                        is_string($data['pending_documents'])
                )
            )
            ->andReturn($normalizedData);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->twice()
            ->withArgs(
                static fn(CloudMessage $message, array $tokens) => $message->jsonSerialize()['data'] ===
                    $normalizedData && count($tokens) === 1
            )
            ->andReturnNull();

        $job = new BackfillDocumentPendingFlagsJob($config->id);
        $job->handle(null, $this->firebaseResolver($messaging), $normalizer);
    }

    public function testSendsLocaleSpecificMessagesToMixedLocaleUsers()
    {
        $creator = $this->createUser();
        $config = $this->createConfig($creator);
        $nepaliUser = $this->createUser(['paper_status' => 'approved', 'settings' => ['lang' => 'ne']]);
        $sinhalaUser = $this->createUser(['paper_status' => 'approved', 'settings' => ['lang' => 'si']]);
        $defaultUser = $this->createUser(['paper_status' => 'approved']);

        foreach ([$nepaliUser, $sinhalaUser, $defaultUser] as $index => $user) {
            UserDevice::query()->create([
                'user_id' => $user->id,
                'device_id' => "device-{$index}",
                'name' => 'Android device',
                'platform' => 'android',
                'fcm_token' => "token-{$index}",
            ]);
        }

        $sentTitles = [];
        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->times(3)
            ->withArgs(function (CloudMessage $message) use (&$sentTitles) {
                $sentTitles[] = $message->jsonSerialize()['notification']['title'];

                return true;
            })
            ->andReturnNull();

        $job = new BackfillDocumentPendingFlagsJob($config->id);
        $job->handle(null, $this->firebaseResolver($messaging));

        $this->assertEqualsCanonicalizing(
            [
                __('lang.document_read_required.title', [], 'ne'),
                __('lang.document_read_required.title', [], 'si'),
                __('lang.document_read_required.title', [], config('app.locale')),
            ],
            $sentTitles
        );
    }
}
