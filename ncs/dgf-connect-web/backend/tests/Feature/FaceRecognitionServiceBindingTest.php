<?php

namespace Tests\Feature;

use App\Contracts\FaceRecognitionProviderInterface;
use App\Services\FaceRecognition\RekognitionProvider;
use App\Services\FaceRecognition\RunPodProvider;
use App\Services\FaceRecognitionService;
use Tests\TestCase;

class FaceRecognitionServiceBindingTest extends TestCase
{
    public function testServiceIsBoundToRekognitionProvider()
    {
        // Set config explicitly for consistency
        config(['services.face_recognition.provider' => 'rekognition']);

        $provider = $this->app->make(FaceRecognitionProviderInterface::class);
        $this->assertInstanceOf(RekognitionProvider::class, $provider);

        $service = $this->app->make(FaceRecognitionService::class);
        $this->assertInstanceOf(FaceRecognitionService::class, $service);
    }

    public function testServiceIsBoundToRunPodProvider()
    {
        // Set config explicitly for consistency
        config([
            'services.face_recognition.provider' => 'runpod',
            'services.runpod_face_recognition.endpoint' => 'http://test-endpoint',
            'services.runpod_face_recognition.api_key' => 'test-key',
            'services.runpod_face_recognition.endpoint_id' => 'test-id',
        ]);

        $provider = $this->app->make(FaceRecognitionProviderInterface::class);
        $this->assertInstanceOf(RunPodProvider::class, $provider);

        $service = $this->app->make(FaceRecognitionService::class);
        $this->assertInstanceOf(FaceRecognitionService::class, $service);
    }
}
