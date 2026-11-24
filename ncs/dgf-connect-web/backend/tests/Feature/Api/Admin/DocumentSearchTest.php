<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocumentReadFixtures;
use Tests\TestCase;

class DocumentSearchTest extends TestCase
{
    use BuildsDocumentReadFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDocumentReadPermissions();
    }

    public function testAdminCanSearchDocumentsByTitleOrId()
    {
        $admin = $this->createGlobalAdmin([PermissionName::DocumentList->value]);
        $document = $this->createDocument();
        $document->update(['title' => 'Safety handbook']);

        $this->createDocument()->update(['title' => 'Payroll guide']);

        $this->actingAs($admin, 'api')
            ->getJson('/admin/document/search?q=Safety')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $document->id)
            ->assertJsonPath('data.0.title', 'Safety handbook');

        $this->actingAs($admin, 'api')
            ->getJson('/admin/document/search?q=' . $document->id)
            ->assertStatus(200)
            ->assertJsonFragment([
                'id' => $document->id,
                'title' => 'Safety handbook',
            ]);
    }

    public function testAdminCanResolveSelectedDocumentByKey()
    {
        $admin = $this->createGlobalAdmin([PermissionName::DocumentList->value]);
        $document = $this->createDocument();
        $document->update(['title' => 'Employee rules']);

        $this->actingAs($admin, 'api')
            ->getJson('/admin/document/search?key=' . $document->id)
            ->assertStatus(200)
            ->assertJson([
                'data' => [
                    [
                        'id' => $document->id,
                        'title' => 'Employee rules',
                    ],
                ],
            ]);
    }
}
