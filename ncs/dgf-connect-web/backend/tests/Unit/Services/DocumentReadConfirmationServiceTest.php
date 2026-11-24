<?php

namespace Tests\Unit\Services;

use App\Models\DocumentReadConfirmation;
use App\Services\DocumentReadConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocumentReadFixtures;
use Tests\TestCase;

class DocumentReadConfirmationServiceTest extends TestCase
{
    use BuildsDocumentReadFixtures;
    use RefreshDatabase;

    private DocumentReadConfirmationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DocumentReadConfirmationService::class);
    }

    public function testGetPendingConfigsReturnsSystemConfigsForPaperApprovedUserOrderedById()
    {
        $creator = $this->createUser();
        $user = $this->createUser(['paper_status' => 'approved']);

        $configB = $this->createConfig($creator);
        $configA = $this->createConfig($creator);

        $pendingConfigs = $this->service->getPendingConfigs($user);

        $this->assertSame([$configB->id, $configA->id], $pendingConfigs->pluck('id')->all());
    }

    public function testGetPendingConfigsReturnsWorkplaceConfigsForUserWithApprovedShiftAtThatWorkplace()
    {
        $creator = $this->createUser();
        $user = $this->createUser(['paper_status' => 'approved']);
        $eligibleWorkplace = $this->createWorkplace();
        $otherWorkplace = $this->createWorkplace();

        $this->assignApprovedShift($user, $eligibleWorkplace, creator: $creator);

        $eligibleConfig = $this->createConfig($creator, workplace: $eligibleWorkplace);
        $this->createConfig($creator, workplace: $otherWorkplace);

        $pendingConfigs = $this->service->getPendingConfigs($user);

        $this->assertSame([$eligibleConfig->id], $pendingConfigs->pluck('id')->all());
    }

    public function testGetPendingConfigsReturnsEmptyForNonApprovedUserWithWorkplaceShift()
    {
        $creator = $this->createUser();
        $user = $this->createUser(['paper_status' => 'pending']);
        $workplace = $this->createWorkplace();

        $this->assignApprovedShift($user, $workplace, creator: $creator);
        $this->createConfig($creator, workplace: $workplace);

        $pendingConfigs = $this->service->getPendingConfigs($user);

        $this->assertTrue($pendingConfigs->isEmpty());
    }

    public function testGetPendingConfigsExcludesInactiveConfigs()
    {
        $creator = $this->createUser();
        $user = $this->createUser(['paper_status' => 'approved']);

        $activeConfig = $this->createConfig($creator);
        $this->createConfig($creator, attributes: ['is_active' => false]);

        $pendingConfigs = $this->service->getPendingConfigs($user);

        $this->assertSame([$activeConfig->id], $pendingConfigs->pluck('id')->all());
    }

    public function testGetPendingConfigsExcludesAlreadyConfirmedConfigs()
    {
        $creator = $this->createUser();
        $user = $this->createUser(['paper_status' => 'approved']);

        $confirmedConfig = $this->createConfig($creator);
        $pendingConfig = $this->createConfig($creator);

        DocumentReadConfirmation::query()->create([
            'user_id' => $user->id,
            'document_required_config_id' => $confirmedConfig->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'Phone'],
        ]);

        $pendingConfigs = $this->service->getPendingConfigs($user);

        $this->assertSame([$pendingConfig->id], $pendingConfigs->pluck('id')->all());
    }

    public function testConfirmCreatesRecordWithAuditData()
    {
        $creator = $this->createUser();
        $user = $this->createUser([
            'paper_status' => 'approved',
            'has_pending_documents' => true,
        ]);
        $config = $this->createConfig($creator);

        $confirmation = $this->service->confirm($user, $config->id, '203.0.113.10', [
            'model' => 'iPhone',
            'os' => 'iOS',
            'app_version' => '1.2.3',
        ]);

        $this->assertNotNull($confirmation);
        $this->assertDatabaseHas('document_read_confirmations', [
            'id' => $confirmation->id,
            'user_id' => $user->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
        ]);
        $this->assertSame('iPhone', $confirmation->device_info['model']);
    }

    public function testConfirmIsIdempotentAndDoesNotOverwriteExistingAuditData()
    {
        $creator = $this->createUser();
        $user = $this->createUser(['paper_status' => 'approved']);
        $config = $this->createConfig($creator);

        $first = $this->service->confirm($user, $config->id, '203.0.113.10', [
            'model' => 'iPhone',
            'os' => 'iOS',
            'app_version' => '1.0.0',
        ]);

        $second = $this->service->confirm($user, $config->id, '198.51.100.20', [
            'model' => 'Android',
            'os' => 'Android',
            'app_version' => '2.0.0',
        ]);

        $this->assertSame($first?->id, $second?->id);
        $this->assertDatabaseCount('document_read_confirmations', 1);

        $stored = DocumentReadConfirmation::query()->firstOrFail();
        $this->assertSame('203.0.113.10', $stored->ip_address);
        $this->assertSame('iPhone', $stored->device_info['model']);
    }

    public function testConfirmReturnsNullForInactiveConfig()
    {
        $creator = $this->createUser();
        $user = $this->createUser(['paper_status' => 'approved']);
        $config = $this->createConfig($creator, attributes: ['is_active' => false]);

        $confirmation = $this->service->confirm($user, $config->id, '203.0.113.10', []);

        $this->assertNull($confirmation);
        $this->assertDatabaseCount('document_read_confirmations', 0);
    }

    public function testEvaluateAndResetFlagResetsFlagWhenNoPendingRemain()
    {
        $creator = $this->createUser();
        $user = $this->createUser([
            'paper_status' => 'approved',
            'has_pending_documents' => true,
        ]);
        $config = $this->createConfig($creator);

        DocumentReadConfirmation::query()->create([
            'user_id' => $user->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'Phone'],
        ]);

        $this->service->evaluateAndResetFlag($user);

        $this->assertFalse($user->fresh()->has_pending_documents);
    }

    public function testSerializePendingConfigsFallsBackToEnglishForNepaliAndSinhalaWhenNotSet()
    {
        $creator = $this->createUser();
        $config = $this->createConfig($creator, attributes: ['title_ne' => null, 'title_si' => null]);

        $result = $this->service->serializePendingConfigs(collect([$config]));

        $this->assertSame('Required document', $result[0]['title_ne']);
        $this->assertSame('Required document', $result[0]['title_si']);
    }

    public function testSerializePendingConfigsUsesNepaliAndSinhalaValuesWhenSet()
    {
        $creator = $this->createUser();
        $config = $this->createConfig(
            $creator,
            attributes: [
                'title_ne' => 'आवश्यक कागजात',
                'title_si' => 'අවශ්‍ය ලේඛනය',
            ]
        );

        $result = $this->service->serializePendingConfigs(collect([$config]));

        $this->assertSame('आवश्यक कागजात', $result[0]['title_ne']);
        $this->assertSame('අවශ්‍ය ලේඛනය', $result[0]['title_si']);
    }
}
