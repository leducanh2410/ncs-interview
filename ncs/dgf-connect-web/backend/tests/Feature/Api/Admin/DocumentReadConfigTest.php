<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Jobs\BackfillDocumentPendingFlagsJob;
use App\Models\DocumentReadConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsDocumentReadFixtures;
use Tests\TestCase;

class DocumentReadConfigTest extends TestCase
{
    use BuildsDocumentReadFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDocumentReadPermissions();
    }

    public function testGlobalAdminCanCreateUpdateDeactivateAndDeleteSystemWideConfigs()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigList->value, PermissionName::ConfigEdit->value]);
        $document = $this->createDocument();
        $workplace = $this->createWorkplace();

        $createResponse = $this->actingAs($admin, 'api')->postJson('/manage/document-read-configs', [
            'document_id' => $document->id,
            'workplace_id' => $workplace->id,
            'title_vi' => 'Tai lieu he thong',
            'title_en' => 'System document',
            'title_ja' => 'システム書類',
            'is_active' => true,
        ]);

        $createResponse
            ->assertStatus(201)
            ->assertJsonPath('data.workplace_id', null)
            ->assertJsonPath('data.scope', 'system');

        $configId = $createResponse->json('data.id');

        $this->actingAs($admin, 'api')
            ->putJson("/manage/document-read-configs/{$configId}", [
                'title_en' => 'Updated system document',
                'is_active' => false,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.title_en', 'Updated system document')
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($admin, 'api')
            ->deleteJson("/manage/document-read-configs/{$configId}")
            ->assertStatus(200)
            ->assertJsonPath('data.success', true);

        $this->assertDatabaseMissing('document_required_configs', ['id' => $configId]);
    }

    public function testSystemConfigPersistsNepaliAndSinhalaTitles()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigList->value, PermissionName::ConfigEdit->value]);
        $document = $this->createDocument();

        $createResponse = $this->actingAs($admin, 'api')->postJson('/manage/document-read-configs', [
            'document_id' => $document->id,
            'title_vi' => 'Tai lieu he thong',
            'title_en' => 'System document',
            'title_ja' => 'システム書類',
            'title_ne' => 'प्रणाली कागजात',
            'title_si' => 'පද්ධති ලේඛනය',
            'is_active' => true,
        ]);

        $createResponse
            ->assertStatus(201)
            ->assertJsonPath('data.title_ne', 'प्रणाली कागजात')
            ->assertJsonPath('data.title_si', 'පද්ධති ලේඛනය');

        $configId = $createResponse->json('data.id');

        $this->actingAs($admin, 'api')
            ->putJson("/manage/document-read-configs/{$configId}", [
                'title_ne' => 'अद्यावधिक कागजात',
                'title_si' => 'යාවත්කාලීන ලේඛනය',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.title_ne', 'अद्यावधिक कागजात')
            ->assertJsonPath('data.title_si', 'යාවත්කාලීන ලේඛනය');

        $this->actingAs($admin, 'api')
            ->putJson("/manage/document-read-configs/{$configId}", [
                'title_ne' => null,
                'title_si' => null,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.title_ne', null)
            ->assertJsonPath('data.title_si', null);
    }

    public function testWorkplaceConfigPersistsNepaliAndSinhalaTitles()
    {
        $workplace = $this->createWorkplace();
        $admin = $this->createWorkplaceAdmin($workplace, [PermissionName::WorkplaceEdit->value]);
        $document = $this->createDocument();

        $createResponse = $this->actingAs($admin, 'api')->postJson(
            "/admin/workplaces/{$workplace->id}/document-read-configs",
            [
                'document_id' => $document->id,
                'title_vi' => 'Tai lieu noi bo',
                'title_en' => 'Workplace document',
                'title_ja' => '現場書類',
                'title_ne' => 'कार्यस्थल कागजात',
                'title_si' => 'සේවා ස්ථාන ලේඛනය',
                'is_active' => true,
            ]
        );

        $createResponse
            ->assertStatus(201)
            ->assertJsonPath('data.title_ne', 'कार्यस्थल कागजात')
            ->assertJsonPath('data.title_si', 'සේවා ස්ථාන ලේඛනය');

        $configId = $createResponse->json('data.id');

        $this->actingAs($admin, 'api')
            ->putJson("/admin/workplaces/{$workplace->id}/document-read-configs/{$configId}", [
                'title_ne' => 'अद्यावधिक कागजात',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.title_ne', 'अद्यावधिक कागजात');

        $this->actingAs($admin, 'api')
            ->putJson("/admin/workplaces/{$workplace->id}/document-read-configs/{$configId}", [
                'title_ne' => null,
                'title_si' => null,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.title_ne', null)
            ->assertJsonPath('data.title_si', null);
    }

    public function testWorkplaceAdminCanCrudTheirWorkplaceConfigs()
    {
        $workplace = $this->createWorkplace();
        $admin = $this->createWorkplaceAdmin($workplace, [PermissionName::WorkplaceEdit->value]);
        $document = $this->createDocument();

        $createResponse = $this->actingAs($admin, 'api')->postJson(
            "/admin/workplaces/{$workplace->id}/document-read-configs",
            [
                'document_id' => $document->id,
                'title_vi' => 'Tai lieu noi bo',
                'title_en' => 'Workplace document',
                'title_ja' => '現場書類',
                'is_active' => true,
            ]
        );

        $createResponse
            ->assertStatus(201)
            ->assertJsonPath('data.workplace_id', $workplace->id)
            ->assertJsonPath('data.scope', 'workplace');

        $configId = $createResponse->json('data.id');

        $this->actingAs($admin, 'api')
            ->putJson("/admin/workplaces/{$workplace->id}/document-read-configs/{$configId}", [
                'title_en' => 'Updated workplace document',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.title_en', 'Updated workplace document');

        $this->actingAs($admin, 'api')
            ->deleteJson("/admin/workplaces/{$workplace->id}/document-read-configs/{$configId}")
            ->assertStatus(200)
            ->assertJsonPath('data.success', true);
    }

    public function testWorkplaceAdminGetsForbiddenOnAnotherWorkplacesConfigs()
    {
        $allowedWorkplace = $this->createWorkplace();
        $forbiddenWorkplace = $this->createWorkplace();
        $admin = $this->createWorkplaceAdmin($allowedWorkplace, [PermissionName::WorkplaceEdit->value]);

        $this->actingAs($admin, 'api')
            ->getJson("/admin/workplaces/{$forbiddenWorkplace->id}/document-read-configs")
            ->assertStatus(403);
    }

    public function testShowReturnsConfigWithRelations()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $document = $this->createDocument();
        $workplace = $this->createWorkplace();
        $config = $this->createConfig($creator, $document, $workplace);

        $this->actingAs($admin, 'api')
            ->getJson("/manage/document-read-configs/{$config->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $config->id)
            ->assertJsonPath('data.scope', 'workplace')
            ->assertJsonPath('data.workplace.id', $workplace->id)
            ->assertJsonPath('data.document.id', $document->id);
    }

    public function testShowReturnsShortenedNameForSriLankaCreator()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigList->value]);
        $creator = $this->createUser([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $creator->info->update(['nationality' => Nationality::SriLanka->value]);
        $config = $this->createConfig($creator, $this->createDocument(), $this->createWorkplace());

        $this->actingAs($admin, 'api')
            ->getJson("/manage/document-read-configs/{$config->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.created_by.name', 'THARINDU RAVISHANN');
    }

    public function testIndexReturnsShortenedNameForSriLankaCreator()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigList->value]);
        $creator = $this->createUser([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $creator->info->update(['nationality' => Nationality::SriLanka->value]);
        $this->createConfig($creator, $this->createDocument(), $this->createWorkplace());

        $response = $this->actingAs($admin, 'api')->getJson('/manage/document-read-configs');

        $response->assertStatus(200);
        $created = collect($response->json('data.data'))->pluck('created_by.name')->all();
        $this->assertContains('THARINDU RAVISHANN', $created);
    }

    public function testShowGetsForbiddenOnAnotherWorkplacesConfig()
    {
        $allowedWorkplace = $this->createWorkplace();
        $forbiddenWorkplace = $this->createWorkplace();
        $admin = $this->createWorkplaceAdmin($allowedWorkplace, [PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $config = $this->createConfig($creator, workplace: $forbiddenWorkplace);

        $this->actingAs($admin, 'api')
            ->getJson("/manage/document-read-configs/{$config->id}")
            ->assertStatus(403);
    }

    public function testUpdatingDocumentIdIsBlockedWhenConfirmationsExist()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigEdit->value, PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $documentA = $this->createDocument('https://example.com/a.pdf');
        $documentB = $this->createDocument('https://example.com/b.pdf');
        $config = $this->createConfig($creator, $documentA);
        $user = $this->createUser();

        DocumentReadConfirmation::query()->create([
            'user_id' => $user->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'Phone'],
        ]);

        $this->actingAs($admin, 'api')
            ->putJson("/manage/document-read-configs/{$config->id}", [
                'document_id' => $documentB->id,
            ])
            ->assertStatus(422);
    }

    public function testDeletingConfigCascadeDeletesConfirmationRecords()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigEdit->value, PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $config = $this->createConfig($creator);
        $user = $this->createUser();

        $confirmation = DocumentReadConfirmation::query()->create([
            'user_id' => $user->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'Phone'],
        ]);

        $this->actingAs($admin, 'api')
            ->deleteJson("/manage/document-read-configs/{$config->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('document_required_configs', ['id' => $config->id]);
        $this->assertDatabaseMissing('document_read_confirmations', ['id' => $confirmation->id]);
    }

    public function testBackfillCountEndpointReturnsCorrectAffectedUserCount()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigEdit->value, PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $config = $this->createConfig($creator);
        $eligibleUser = $this->createUser(['paper_status' => 'approved']);
        $confirmedUser = $this->createUser(['paper_status' => 'approved']);
        $this->createUser(['paper_status' => 'registered']);

        DocumentReadConfirmation::query()->create([
            'user_id' => $confirmedUser->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'Phone'],
        ]);

        $this->actingAs($admin, 'api')
            ->getJson("/manage/document-read-configs/{$config->id}/backfill-count")
            ->assertStatus(200)
            ->assertJsonPath('data.affected_count', 1);

        $this->assertFalse($eligibleUser->fresh()->has_pending_documents);
    }

    public function testBackfillTriggerEndpointDispatchesJob()
    {
        Queue::fake();

        $admin = $this->createGlobalAdmin([PermissionName::ConfigEdit->value, PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $config = $this->createConfig($creator);
        $this->createUser(['paper_status' => 'approved']);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/document-read-configs/{$config->id}/backfill")
            ->assertStatus(202)
            ->assertJsonPath('data.message', 'Backfill job dispatched');

        Queue::assertPushed(BackfillDocumentPendingFlagsJob::class, function ($job) use ($config) {
            return $job->configId === $config->id;
        });
    }

    public function testBackfillTriggerOnInactiveConfigReturns422()
    {
        Queue::fake();

        $admin = $this->createGlobalAdmin([PermissionName::ConfigEdit->value, PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $config = $this->createConfig($creator, attributes: ['is_active' => false]);

        $this->actingAs($admin, 'api')
            ->postJson("/manage/document-read-configs/{$config->id}/backfill")
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function testUnifiedConfigListReturnsScopedResultsForWorkplaceAdmin()
    {
        $creator = $this->createUser();
        $allowedWorkplace = $this->createWorkplace();
        $otherWorkplace = $this->createWorkplace();
        $admin = $this->createWorkplaceAdmin(
            $allowedWorkplace,
            [PermissionName::WorkplaceEdit->value],
            [PermissionName::ConfigList->value]
        );

        $systemConfig = $this->createConfig($creator);
        $allowedConfig = $this->createConfig($creator, workplace: $allowedWorkplace);
        $otherConfig = $this->createConfig($creator, workplace: $otherWorkplace);

        $response = $this->actingAs($admin, 'api')->getJson('/manage/document-read-configs');

        $response->assertStatus(200);
        $this->assertSame(
            [$systemConfig->id, $allowedConfig->id],
            collect($response->json('data.data'))->pluck('id')->all()
        );
        $this->assertNotContains($otherConfig->id, collect($response->json('data.data'))->pluck('id')->all());
    }

    public function testAllConfirmationsWorkplaceFilterIgnoresEmptyStringFilters()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $workplace = $this->createWorkplace();
        $userA = $this->createUser();
        $userB = $this->createUser();
        $config = $this->createConfig($creator, workplace: $workplace);

        $confirmationA = DocumentReadConfirmation::query()->create([
            'user_id' => $userA->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'Phone'],
        ]);
        $confirmationB = DocumentReadConfirmation::query()->create([
            'user_id' => $userB->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.11',
            'device_info' => ['model' => 'Phone'],
        ]);

        $response = $this->actingAs($admin, 'api')->getJson(
            '/manage/document-read-confirmations?config_id=&workplace_id=' . $workplace->id . '&user_id='
        );

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertContains($confirmationA->id, $ids);
        $this->assertContains($confirmationB->id, $ids);
    }

    public function testAllConfirmationsFilterByWorkplaceId()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $workplaceA = $this->createWorkplace();
        $workplaceB = $this->createWorkplace();
        $user = $this->createUser();

        $configA = $this->createConfig($creator, workplace: $workplaceA);
        $configB = $this->createConfig($creator, workplace: $workplaceB);

        $confirmationA = DocumentReadConfirmation::query()->create([
            'user_id' => $user->id,
            'document_required_config_id' => $configA->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'A'],
        ]);
        DocumentReadConfirmation::query()->create([
            'user_id' => $user->id,
            'document_required_config_id' => $configB->id,
            'ip_address' => '203.0.113.11',
            'device_info' => ['model' => 'B'],
        ]);

        $response = $this->actingAs($admin, 'api')->getJson(
            '/manage/document-read-confirmations?workplace_id=' . $workplaceA->id
        );

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertContains($confirmationA->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function testAllConfirmationsFilterByConfigId()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $user = $this->createUser();

        $configA = $this->createConfig($creator);
        $configB = $this->createConfig($creator);

        $confirmationA = DocumentReadConfirmation::query()->create([
            'user_id' => $user->id,
            'document_required_config_id' => $configA->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'A'],
        ]);
        DocumentReadConfirmation::query()->create([
            'user_id' => $user->id,
            'document_required_config_id' => $configB->id,
            'ip_address' => '203.0.113.11',
            'device_info' => ['model' => 'B'],
        ]);

        $response = $this->actingAs($admin, 'api')->getJson(
            '/manage/document-read-confirmations?config_id=' . $configA->id
        );

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertSame([$confirmationA->id], $ids);
    }

    public function testAllConfirmationsFilterByUserId()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $userA = $this->createUser();
        $userB = $this->createUser();
        $config = $this->createConfig($creator);

        $confirmationA = DocumentReadConfirmation::query()->create([
            'user_id' => $userA->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'A'],
        ]);
        DocumentReadConfirmation::query()->create([
            'user_id' => $userB->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.11',
            'device_info' => ['model' => 'B'],
        ]);

        $response = $this->actingAs($admin, 'api')->getJson(
            '/manage/document-read-confirmations?user_id=' . $userA->id
        );

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertSame([$confirmationA->id], $ids);
    }

    public function testAllConfirmationsFilterByName()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $userA = $this->createUser(['first_name' => 'Hanako', 'last_name' => 'Tanaka']);
        $userB = $this->createUser(['first_name' => 'Taro', 'last_name' => 'Sato']);
        $config = $this->createConfig($creator);

        $confirmationA = DocumentReadConfirmation::query()->create([
            'user_id' => $userA->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'A'],
        ]);
        DocumentReadConfirmation::query()->create([
            'user_id' => $userB->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.11',
            'device_info' => ['model' => 'B'],
        ]);

        $response = $this->actingAs($admin, 'api')->getJson('/manage/document-read-confirmations?name=Tanaka');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertSame([$confirmationA->id], $ids);
    }

    public function testAllConfirmationsShowsShortenedNameForSriLankaUser()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $user = $this->createUser(['first_name' => 'Ragalage Tharindu Ravishann', 'last_name' => 'Karnasooriya']);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);
        $config = $this->createConfig($creator);

        DocumentReadConfirmation::query()->create([
            'user_id' => $user->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'A'],
        ]);

        $response = $this->actingAs($admin, 'api')->getJson('/manage/document-read-confirmations');

        $response->assertStatus(200);
        $row = collect($response->json('data.data'))->firstWhere('user_id', $user->id);
        $this->assertSame('THARINDU RAVISHANN', $row['user']['name']);
    }

    public function testAllConfirmationsFilterByShortNameMatchesReversedFallbackName()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $target = $this->createUser(['first_name' => 'Deshan', 'last_name' => 'Lokuruge']);
        $target->info->update(['nationality' => Nationality::SriLanka->value]);
        $other = $this->createUser(['first_name' => 'Taro', 'last_name' => 'Sato']);
        $config = $this->createConfig($creator);

        $confirmationA = DocumentReadConfirmation::query()->create([
            'user_id' => $target->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'A'],
        ]);
        DocumentReadConfirmation::query()->create([
            'user_id' => $other->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.11',
            'device_info' => ['model' => 'B'],
        ]);

        // "DESHAN LOKURUGE" is the short_name shown on screen (reversed-fallback case), but the
        // raw CONCAT(last_name, first_name) is "Lokuruge Deshan" — searching what's displayed must match.
        $response = $this->actingAs($admin, 'api')->getJson('/manage/document-read-confirmations?name=DESHAN LOKURUGE');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertSame([$confirmationA->id], $ids);
    }

    public function testConfigConfirmationsShowsShortenedNameForSriLankaUser()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $user = $this->createUser(['first_name' => 'Ragalage Tharindu Ravishann', 'last_name' => 'Karnasooriya']);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);
        $config = $this->createConfig($creator);

        DocumentReadConfirmation::query()->create([
            'user_id' => $user->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'A'],
        ]);

        $response = $this->actingAs($admin, 'api')->getJson(
            "/manage/document-read-configs/{$config->id}/confirmations"
        );

        $response->assertStatus(200);
        $row = collect($response->json('data.data'))->firstWhere('user_id', $user->id);
        $this->assertSame('THARINDU RAVISHANN', $row['user']['name']);
    }

    public function testAllConfirmationsFilterByStaffNo()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $userA = $this->createUser(['staff_no' => '12345']);
        $userB = $this->createUser(['staff_no' => '67890']);
        $config = $this->createConfig($creator);

        $confirmationA = DocumentReadConfirmation::query()->create([
            'user_id' => $userA->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'A'],
        ]);
        DocumentReadConfirmation::query()->create([
            'user_id' => $userB->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.11',
            'device_info' => ['model' => 'B'],
        ]);

        $response = $this->actingAs($admin, 'api')->getJson('/manage/document-read-confirmations?staff_no=12345');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertSame([$confirmationA->id], $ids);
    }

    public function testConfigConfirmationsFilterByUserId()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $userA = $this->createUser();
        $userB = $this->createUser();
        $config = $this->createConfig($creator);

        $confirmationA = DocumentReadConfirmation::query()->create([
            'user_id' => $userA->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'A'],
        ]);
        DocumentReadConfirmation::query()->create([
            'user_id' => $userB->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.11',
            'device_info' => ['model' => 'B'],
        ]);

        $response = $this->actingAs($admin, 'api')->getJson(
            "/manage/document-read-configs/{$config->id}/confirmations?user_id={$userA->id}"
        );

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertSame([$confirmationA->id], $ids);
    }

    public function testConfigConfirmationsEmptyStringUserIdDoesNotFilter()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $userA = $this->createUser();
        $userB = $this->createUser();
        $config = $this->createConfig($creator);

        $confirmationA = DocumentReadConfirmation::query()->create([
            'user_id' => $userA->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'A'],
        ]);
        $confirmationB = DocumentReadConfirmation::query()->create([
            'user_id' => $userB->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.11',
            'device_info' => ['model' => 'B'],
        ]);

        $response = $this->actingAs($admin, 'api')->getJson(
            "/manage/document-read-configs/{$config->id}/confirmations?user_id="
        );

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertContains($confirmationA->id, $ids);
        $this->assertContains($confirmationB->id, $ids);
    }

    public function testSystemConfigStatsCountsConfirmedAndEligibleAgainstLivePool()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigEdit->value, PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $config = $this->createConfig($creator);

        $confirmedUser = $this->createUser(['paper_status' => 'approved']);
        $this->createUser(['paper_status' => 'approved']);
        $this->createUser(['paper_status' => 'approved']);
        $this->createUser(['paper_status' => 'registered']);

        DocumentReadConfirmation::query()->create([
            'user_id' => $confirmedUser->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'Phone'],
        ]);

        $response = $this->actingAs($admin, 'api')->getJson("/manage/document-read-configs/{$config->id}/stats");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.eligible_count', 3)
            ->assertJsonPath('data.confirmed_count', 1)
            ->assertJsonPath('data.pending_count', 2)
            ->assertJsonPath('data.confirmation_rate', 0.3333);
    }

    public function testStatsExcludeConfirmationsFromUsersNoLongerInEligiblePool()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigEdit->value, PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $config = $this->createConfig($creator);

        $stillEligible = $this->createUser(['paper_status' => 'approved']);
        $departedConfirmer = $this->createUser(['paper_status' => 'registered']);

        DocumentReadConfirmation::query()->create([
            'user_id' => $stillEligible->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'Phone'],
        ]);
        DocumentReadConfirmation::query()->create([
            'user_id' => $departedConfirmer->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.11',
            'device_info' => ['model' => 'Phone'],
        ]);

        $response = $this->actingAs($admin, 'api')->getJson("/manage/document-read-configs/{$config->id}/stats");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.eligible_count', 1)
            ->assertJsonPath('data.confirmed_count', 1)
            ->assertJsonPath('data.pending_count', 0)
            ->assertJsonPath('data.confirmation_rate', 1);
    }

    public function testStatsReturnZeroRateWhenEligiblePoolIsEmpty()
    {
        $admin = $this->createGlobalAdmin([PermissionName::ConfigEdit->value, PermissionName::ConfigList->value]);
        $creator = $this->createUser();
        $config = $this->createConfig($creator);

        $this->createUser(['paper_status' => 'registered']);

        $response = $this->actingAs($admin, 'api')->getJson("/manage/document-read-configs/{$config->id}/stats");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.eligible_count', 0)
            ->assertJsonPath('data.confirmed_count', 0)
            ->assertJsonPath('data.pending_count', 0)
            ->assertJsonPath('data.confirmation_rate', 0);
    }

    public function testWorkplaceConfigStatsIntersectWithApprovedShiftAtWorkplace()
    {
        $workplace = $this->createWorkplace();
        $admin = $this->createWorkplaceAdmin($workplace, [PermissionName::WorkplaceEdit->value]);
        $creator = $this->createUser();
        $config = $this->createConfig($creator, workplace: $workplace);

        $shiftedAndConfirmed = $this->createUser(['paper_status' => 'approved']);
        $shiftedNotConfirmed = $this->createUser(['paper_status' => 'approved']);
        $approvedButNoShift = $this->createUser(['paper_status' => 'approved']);

        $this->assignApprovedShift($shiftedAndConfirmed, $workplace);
        $this->assignApprovedShift($shiftedNotConfirmed, $workplace);

        DocumentReadConfirmation::query()->create([
            'user_id' => $shiftedAndConfirmed->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'Phone'],
        ]);
        DocumentReadConfirmation::query()->create([
            'user_id' => $approvedButNoShift->id,
            'document_required_config_id' => $config->id,
            'ip_address' => '203.0.113.11',
            'device_info' => ['model' => 'Phone'],
        ]);

        $response = $this->actingAs($admin, 'api')->getJson(
            "/admin/workplaces/{$workplace->id}/document-read-configs/{$config->id}/stats"
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.eligible_count', 2)
            ->assertJsonPath('data.confirmed_count', 1)
            ->assertJsonPath('data.pending_count', 1)
            ->assertJsonPath('data.confirmation_rate', 0.5);
    }

    public function testUnifiedAuditEndpointReturnsScopedConfirmationsForWorkplaceAdmin()
    {
        $creator = $this->createUser();
        $allowedWorkplace = $this->createWorkplace();
        $otherWorkplace = $this->createWorkplace();
        $admin = $this->createWorkplaceAdmin(
            $allowedWorkplace,
            [PermissionName::WorkplaceEdit->value],
            [PermissionName::ConfigList->value]
        );
        $user = $this->createUser();

        $systemConfig = $this->createConfig($creator);
        $allowedConfig = $this->createConfig($creator, workplace: $allowedWorkplace);
        $otherConfig = $this->createConfig($creator, workplace: $otherWorkplace);

        $systemConfirmation = DocumentReadConfirmation::query()->create([
            'user_id' => $user->id,
            'document_required_config_id' => $systemConfig->id,
            'ip_address' => '203.0.113.10',
            'device_info' => ['model' => 'System'],
        ]);
        $allowedConfirmation = DocumentReadConfirmation::query()->create([
            'user_id' => $user->id,
            'document_required_config_id' => $allowedConfig->id,
            'ip_address' => '203.0.113.11',
            'device_info' => ['model' => 'Allowed'],
        ]);
        $otherConfirmation = DocumentReadConfirmation::query()->create([
            'user_id' => $user->id,
            'document_required_config_id' => $otherConfig->id,
            'ip_address' => '203.0.113.12',
            'device_info' => ['model' => 'Other'],
        ]);

        $response = $this->actingAs($admin, 'api')->getJson('/manage/document-read-confirmations');

        $response->assertStatus(200);
        $confirmationIds = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertContains($systemConfirmation->id, $confirmationIds);
        $this->assertContains($allowedConfirmation->id, $confirmationIds);
        $this->assertNotContains($otherConfirmation->id, $confirmationIds);
    }
}
