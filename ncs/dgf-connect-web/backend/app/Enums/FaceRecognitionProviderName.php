<?php

namespace App\Enums;

enum FaceRecognitionProviderName: string
{
    use EnumTrait;

    case RunPod = 'runpod';
    case Rekognition = 'rekognition';
}
