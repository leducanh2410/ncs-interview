<?php

namespace Tests\Feature\Api\User;

use App\Enums\UserPaperStatus;
use App\Models\DocumentReadConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocumentReadFixtures;
use Tests\TestCase;

class DocumentReadConfirmationTest extends TestCase
{
    use BuildsDocumentReadFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDocumentReadPermissions();
    }

    public function testConfirmEndpointPersistsRecordWithIpAddressAndDeviceInfo()
    {
        $creator = $this->createUser();
        $user = $this->createUser([
            'paper_status' => UserPaperStatus::Approved->value,
            'has_pending_documents' => true,
        ]);
        $this->attachUserRole($user);
        $config = $this->createConfig($creator);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->actingAs($user, 'api')
            ->postJson("/user/document-read-configs/{$config->id}/confirm", [
                'device_info' => [
                    'model' => 'iPhone',
                    'os' => 'iOS',
                    'app_version' => '1.2.3',
                ],
            ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.confirmed', true)
            ->assertJsonPath('data.has_pending_documents', false);

        $this->assertDatabaseHas('document_read_confirmations', [
            'user_id' => $user->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
        ]);
    }

    public function testConfirmEndpointIsIdempotentAndPreservesOriginalAuditData()
    {
        $creator = $this->createUser();
        $user = $this->createUser([
            'paper_status' => UserPaperStatus::Approved->value,
            'has_pending_documents' => true,
        ]);
        $this->attachUserRole($user);
        $config = $this->createConfig($creator);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->actingAs($user, 'api')
            ->postJson("/user/document-read-configs/{$config->id}/confirm", [
                'device_info' => [
                    'model' => 'iPhone',
                    'os' => 'iOS',
                    'app_version' => '1.0.0',
                ],
            ])
            ->assertStatus(200);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->actingAs($user, 'api')
            ->postJson("/user/document-read-configs/{$config->id}/confirm", [
                'device_info' => [
                    'model' => 'Android',
                    'os' => 'Android',
                    'app_version' => '2.0.0',
                ],
            ])
            ->assertStatus(200);

        $this->assertDatabaseCount('document_read_confirmations', 1);

        $confirmation = DocumentReadConfirmation::query()->firstOrFail();
        $this->assertSame('203.0.113.10', $confirmation->ip_address);
        $this->assertSame('iPhone', $confirmation->device_info['model']);
    }

    public function testConfirmOnInactiveConfigReturnsSuccessWithoutCreatingRecord()
    {
        $creator = $this->createUser();
        $user = $this->createUser([
            'paper_status' => UserPaperStatus::Approved->value,
            'has_pending_documents' => true,
        ]);
        $this->attachUserRole($user);
        $config = $this->createConfig($creator, attributes: ['is_active' => false]);

        $this->actingAs($user, 'api')
            ->postJson("/user/document-read-configs/{$config->id}/confirm", [
                'device_info' => ['model' => 'iPhone'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.confirmed', true);

        $this->assertDatabaseCount('document_read_confirmations', 0);
    }

    public function testFlagIsResetToFalseAfterAllConfigsAreConfirmed()
    {
        $creator = $this->createUser();
        $user = $this->createUser([
            'paper_status' => UserPaperStatus::Approved->value,
            'has_pending_documents' => true,
        ]);
        $this->attachUserRole($user);
        $firstConfig = $this->createConfig($creator);
        $secondConfig = $this->createConfig($creator);

        $this->actingAs($user, 'api')
            ->postJson("/user/document-read-configs/{$firstConfig->id}/confirm", [])
            ->assertStatus(200)
            ->assertJsonPath('data.has_pending_documents', true);

        $this->actingAs($user, 'api')
            ->postJson("/user/document-read-configs/{$secondConfig->id}/confirm", [])
            ->assertStatus(200)
            ->assertJsonPath('data.has_pending_documents', false);
    }

    public function testLoginResponseIncludesPendingDocumentsOrderedByIdWhenFlagIsTrue()
    {
        $creator = $this->createUser();
        $user = $this->createUser([
            'paper_status' => UserPaperStatus::Approved->value,
            'has_pending_documents' => true,
            'email' => 'employee@example.com',
            'password' => 'password',
        ]);
        $this->attachUserRole($user);

        $configLater = $this->createConfig($creator, $this->createDocument('https://example.com/later.pdf'));
        $configEarlier = $this->createConfig($creator, $this->createDocument('https://example.com/earlier.pdf'));

        $response = $this->postJson('/auth/login', [
            'email' => 'employee@example.com',
            'password' => 'password',
            'device_id' => 'device-1',
            'device_name' => 'iPhone',
            'platform' => 'ios',
            'fcm_token' => null,
        ]);

        $response->assertStatus(200);
        $this->assertSame(
            [$configLater->id, $configEarlier->id],
            collect($response->json('data.pending_documents'))->pluck('id')->all()
        );
        $this->assertSame($configLater->document_id, $response->json('data.pending_documents.0.document.id'));
        $this->assertSame('https://example.com/later.pdf', $response->json('data.pending_documents.0.document.url'));
    }

    public function testAuthCheckResponseIncludesPendingDocumentsOrderedByIdWhenFlagIsTrue()
    {
        $creator = $this->createUser();
        $user = $this->createUser([
            'paper_status' => UserPaperStatus::Approved->value,
            'has_pending_documents' => true,
        ]);
        $this->attachUserRole($user);

        $configLater = $this->createConfig($creator, $this->createDocument('https://example.com/check-later.pdf'));
        $configEarlier = $this->createConfig($creator, $this->createDocument('https://example.com/check-earlier.pdf'));

        $response = $this->actingAs($user, 'api')->getJson('/auth/check');

        $response->assertStatus(200);
        $this->assertSame(
            [$configLater->id, $configEarlier->id],
            collect($response->json('data.pending_documents'))->pluck('id')->all()
        );
        $this->assertSame($configLater->document_id, $response->json('data.pending_documents.0.document.id'));
        $this->assertSame(
            'https://example.com/check-later.pdf',
            $response->json('data.pending_documents.0.document.url')
        );
    }

    public function testLoginResponseIncludesEmptyPendingDocumentsWhenFlagIsFalse()
    {
        $user = $this->createUser([
            'paper_status' => UserPaperStatus::Approved->value,
            'has_pending_documents' => false,
            'email' => 'employee-no-pending@example.com',
            'password' => 'password',
        ]);
        $this->attachUserRole($user);

        $response = $this->postJson('/auth/login', [
            'email' => 'employee-no-pending@example.com',
            'password' => 'password',
            'device_id' => 'device-2',
            'device_name' => 'Android',
            'platform' => 'android',
            'fcm_token' => null,
        ]);

        $response->assertStatus(200)->assertJsonPath('data.pending_documents', []);
    }

    public function testLoginSelfHealsStaleFlagWhenNoPendingConfigsRemain()
    {
        $creator = $this->createUser();
        $user = $this->createUser([
            'paper_status' => UserPaperStatus::Approved->value,
            'has_pending_documents' => true,
            'email' => 'employee-stale@example.com',
            'password' => 'password',
        ]);
        $this->attachUserRole($user);
        $this->createConfig($creator, attributes: ['is_active' => false]);

        $response = $this->postJson('/auth/login', [
            'email' => 'employee-stale@example.com',
            'password' => 'password',
            'device_id' => 'device-3',
            'device_name' => 'Android',
            'platform' => 'android',
            'fcm_token' => null,
        ]);

        $response->assertStatus(200)->assertJsonPath('data.pending_documents', []);

        $this->assertFalse($user->fresh()->has_pending_documents);
    }

    public function testAuthCheckResponseExcludesPendingDocumentsForNonApprovedUser()
    {
        $creator = $this->createUser();
        $user = $this->createUser([
            'paper_status' => UserPaperStatus::Pending->value,
            'has_pending_documents' => true,
        ]);
        $this->attachUserRole($user);

        $workplace = $this->createWorkplace();
        $this->assignApprovedShift($user, $workplace, creator: $creator);
        $this->createConfig($creator, workplace: $workplace);

        $response = $this->actingAs($user, 'api')->getJson('/auth/check');

        $response->assertStatus(200)->assertJsonPath('data.pending_documents', []);
        $this->assertFalse($user->fresh()->has_pending_documents);
    }
}
