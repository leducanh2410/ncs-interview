<?php

namespace Tests\Unit\Actions\Common\Firebase;

use App\Actions\Common\Firebase\NormalizeFirebaseMessageDataAction;
use Tests\TestCase;

class NormalizeFirebaseMessageDataActionTest extends TestCase
{
    public function testNormalizesFirebaseDataValuesToStrings(): void
    {
        $result = app(NormalizeFirebaseMessageDataAction::class)->execute([
            'string' => 'value',
            'integer' => 123,
            'float' => 4.5,
            'true' => true,
            'false' => false,
            'null' => null,
            'array' => ['nested' => 1],
        ]);

        $this->assertSame(
            [
                'string' => 'value',
                'integer' => '123',
                'float' => '4.5',
                'true' => 'true',
                'false' => 'false',
                'null' => '',
                'array' => '{"nested":1}',
            ],
            $result
        );
    }
}
