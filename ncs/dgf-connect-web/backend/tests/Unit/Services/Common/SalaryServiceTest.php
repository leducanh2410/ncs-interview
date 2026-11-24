<?php

namespace Tests\Unit\Services\Common;

use App\Facades\SalaryFacade;
use App\Models\Salary;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SalaryServiceTest extends TestCase
{
    public function testGetMinMaxForRequest()
    {
        Carbon::setTestNow(Carbon::create(2025));

        $result = SalaryFacade::getMinMaxForRequest(collect());

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['min']);
        $this->assertEquals(0, $result['max']);
        $this->assertEquals(0, $result['unfinalized']);
        $this->assertEquals(0, $result['min_transfer']);
        $this->assertEquals(0, $result['max_transfer']);

        $result = SalaryFacade::getMinMaxForRequest(
            collect([Salary::factory()->make(['is_finalized' => 1, 'total_amount' => 329])])
        );

        $this->assertIsArray($result);
        $this->assertEquals(329, $result['min']);
        $this->assertEquals(329, $result['max']);
        $this->assertEquals(0, $result['unfinalized']);
        $this->assertEquals(0, $result['min_transfer']);
        $this->assertEquals(0, $result['max_transfer']);

        $result = SalaryFacade::getMinMaxForRequest(
            collect([Salary::factory()->make(['is_finalized' => 1, 'total_amount' => 1329])])
        );

        $this->assertIsArray($result);
        $this->assertEquals(1329, $result['min']);
        $this->assertEquals(1329, $result['max']);
        $this->assertEquals(0, $result['unfinalized']);
        $this->assertEquals(0, $result['min_transfer']);
        $this->assertEquals(0, $result['max_transfer']);

        $result = SalaryFacade::getMinMaxForRequest(
            collect([
                Salary::factory()->make(['is_finalized' => 1, 'total_amount' => 329, 'month' => '2024-01']),
                Salary::factory()->make(['is_finalized' => 1, 'total_amount' => 329, 'month' => '2024-02']),
                Salary::factory()->make(['is_finalized' => 1, 'total_amount' => 329, 'month' => '2024-03']),
                Salary::factory()->make(['is_finalized' => 1, 'total_amount' => 329, 'month' => '2024-04']),
            ])
        );

        $this->assertIsArray($result);
        $this->assertEquals(329 * 4, $result['min']);
        $this->assertEquals(329 * 4, $result['max']);
        $this->assertEquals(0, $result['unfinalized']);
        $this->assertEquals(0, $result['min_transfer']);
        $this->assertEquals(0, $result['max_transfer']);

        $result = SalaryFacade::getMinMaxForRequest(
            collect([Salary::factory()->make(['is_finalized' => 1, 'total_amount' => 2000, 'month' => '2024-01'])])
        );

        $this->assertIsArray($result);
        $this->assertEquals(2000, $result['min']);
        $this->assertEquals(2000, $result['max']);
        $this->assertEquals(0, $result['unfinalized']);
        $this->assertEquals(1000, $result['min_transfer']);
        $this->assertEquals(1000, $result['max_transfer']);

        $result = SalaryFacade::getMinMaxForRequest(
            collect([
                Salary::factory()->make(['is_finalized' => 1, 'total_amount' => 2000, 'month' => '2024-01']),
                Salary::factory()->make(['is_finalized' => 1, 'total_amount' => 200, 'month' => '2024-02']),
            ])
        );

        $this->assertIsArray($result);
        $this->assertEquals(2200, $result['min']);
        $this->assertEquals(2200, $result['max']);
        $this->assertEquals(0, $result['unfinalized']);
        $this->assertEquals(0, $result['min_transfer']);
        $this->assertEquals(0, $result['max_transfer']);
    }

    public function testOnlyFinalized()
    {
        Carbon::setTestNow(Carbon::create(2025));

        $result = SalaryFacade::getMinMaxForRequest(
            collect([
                Salary::factory()->make(['is_finalized' => 1, 'total_amount' => 1800, 'month' => '2024-01']),
                Salary::factory()->make(['is_finalized' => 1, 'total_amount' => 400, 'month' => '2024-02']),
            ])
        );

        $this->assertIsArray($result);
        $this->assertEquals(2200, $result['min']);
        $this->assertEquals(2200, $result['max']);
        $this->assertEquals(0, $result['unfinalized']);
        $this->assertEquals(0, $result['min_transfer']);
        $this->assertEquals(0, $result['max_transfer']);

        $result = SalaryFacade::getMinMaxForRequest(
            collect([
                Salary::factory()->make(['is_finalized' => 1, 'total_amount' => 1800, 'month' => '2024-01']),
                Salary::factory()->make(['is_finalized' => 1, 'total_amount' => 600, 'month' => '2024-02']),
            ])
        );

        $this->assertIsArray($result);
        $this->assertEquals(2400, $result['min']);
        $this->assertEquals(2400, $result['max']);
        $this->assertEquals(0, $result['unfinalized']);
        $this->assertEquals(2000, $result['min_transfer']);
        $this->assertEquals(2000, $result['max_transfer']);
    }

    public function testBothFinalizedAndUnfinalized()
    {
        Carbon::setTestNow(Carbon::create(2025));

        $result = SalaryFacade::getMinMaxForRequest(
            collect([
                Salary::factory()->make(['is_finalized' => 1, 'total_amount' => 1800, 'month' => '2024-01']),
                Salary::factory()->make(['is_finalized' => 1, 'total_amount' => 400, 'month' => '2024-02']),
                Salary::factory()->make(['is_finalized' => 0, 'total_amount' => 4000, 'month' => '2024-03']),
            ])
        );

        $this->assertIsArray($result);
        $this->assertEquals(2200, $result['min']);
        $this->assertEquals(5400, $result['max']);
        $this->assertEquals(3200, $result['unfinalized']);
        $this->assertEquals(3000, $result['min_transfer']);
        $this->assertEquals(5000, $result['max_transfer']);

        Carbon::setTestNow();
    }
}
