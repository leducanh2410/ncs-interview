<?php

namespace Tests\Unit\Services;

use App\Contracts\FaceRecognitionProviderInterface;
use App\Services\FaceRecognition\Responses\AddUserBatchResponse;
use App\Services\FaceRecognition\Responses\AddUserResponse;
use App\Services\FaceRecognition\Responses\RemoveUserResponse;
use App\Services\FaceRecognition\Responses\VerifyResponse;
use App\Services\FaceRecognitionService;
use Mockery;
use Tests\TestCase;

class FaceRecognitionServiceTest extends TestCase
{
    private $provider;

    private $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = Mockery::mock(FaceRecognitionProviderInterface::class);
        $this->service = new FaceRecognitionService($this->provider);
    }

    public function testAddUser()
    {
        $userId = 'user123';
        $imageUrl = 'http://example.com/image.jpg';
        $response = new AddUserResponse(success: true, vectorId: 'vec123');

        $this->provider->shouldReceive('addUser')->once()->with($userId, $imageUrl)->andReturn($response);

        $result = $this->service->addUser($userId, $imageUrl);

        $this->assertEquals($response, $result);
    }

    public function testAddUserBatch()
    {
        $items = [['user_id' => 'user1', 'image_url' => 'url1']];
        $response = new AddUserBatchResponse(results: [], total: 1, successCount: 1, errorCount: 0);

        $this->provider->shouldReceive('addUserBatch')->once()->with($items)->andReturn($response);

        $result = $this->service->addUserBatch($items);

        $this->assertEquals($response, $result);
    }

    public function testRemoveUser()
    {
        $userId = 'user123';
        $response = new RemoveUserResponse(success: true, deletedCount: 1);

        $this->provider->shouldReceive('removeUser')->once()->with($userId, null)->andReturn($response);

        $result = $this->service->removeUser($userId);

        $this->assertEquals($response, $result);
    }

    public function testVerify()
    {
        $userId = 'user123';
        $image = 'base64image';
        $response = new VerifyResponse(verified: true, userId: $userId, confidence: 0.95);

        $this->provider->shouldReceive('verify')->once()->with($userId, $image, true)->andReturn($response);

        $result = $this->service->verify($userId, $image, true);

        $this->assertEquals($response, $result);
    }

    public function testHealth()
    {
        $this->provider->shouldReceive('health')->once()->andReturn(true);

        $result = $this->service->health();

        $this->assertTrue($result);
    }

    public function testWarmUp()
    {
        $this->provider->shouldReceive('warmUp')->once()->andReturn(true);

        $result = $this->service->warmUp();

        $this->assertTrue($result);
    }

    public function testSupportsRealtimeAntiSpoofing()
    {
        $this->provider->shouldReceive('supportsRealtimeAntiSpoofing')->once()->andReturn(true);

        $result = $this->service->supportsRealtimeAntiSpoofing();

        $this->assertTrue($result);
    }
}
