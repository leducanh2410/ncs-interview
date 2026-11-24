<?php

namespace Tests\Unit\Models;

use App\Enums\ExamType;
use App\Models\Exam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamTest extends TestCase
{
    use RefreshDatabase;

    public function testTypeAccessorTreatsNullAsAssessment(): void
    {
        $exam = Exam::create([
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
        ]);

        $this->assertNull($exam->getRawOriginal('type'));
        $this->assertEquals(ExamType::Assessment, $exam->type);
    }

    public function testTypeAccessorReturnsExternalFormPrepWhenSet(): void
    {
        $exam = Exam::create([
            'type' => ExamType::ExternalFormPrep->value,
            'is_required' => true,
            'retry_limit' => 0,
            'min_score' => 0,
            'time_limit' => 120,
        ]);

        $this->assertEquals(ExamType::ExternalFormPrep, $exam->type);
    }
}
