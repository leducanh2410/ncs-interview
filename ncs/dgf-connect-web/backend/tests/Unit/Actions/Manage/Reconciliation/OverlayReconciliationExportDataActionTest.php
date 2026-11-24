<?php

namespace Tests\Unit\Actions\Manage\Reconciliation;

use App\Actions\Manage\Reconciliation\OverlayReconciliationExportDataAction;
use App\Enums\ReconciliationDecision;
use App\Models\CheckIn;
use App\Models\ReconciliationRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReconciliationFixtures;
use Tests\TestCase;

class OverlayReconciliationExportDataActionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReconciliationFixtures;

    public function testAmazonCheckInThatIsOnTimeClearsLateStatusAndReason(): void
    {
        $checkIn = $this->lateCheckInWithReason();

        $item = $this->exportRow($checkIn, '2026-08-25 20:55:00');

        $this->assertSame('', $item['attendance_status']);
        $this->assertSame('', $item['attendance_reason']);
        $this->assertSame(30, $checkIn->refresh()->late_minutes);
        $this->assertSame('Bus delay', $checkIn->late_reason);
    }

    public function testAmazonCheckInThatIsStillLateUsesTheOverlaidMinutes(): void
    {
        $checkIn = $this->lateCheckInWithReason();

        $item = $this->exportRow($checkIn, '2026-08-25 21:40:00');

        $this->assertSame('Đi muộn (40 phút)', $item['attendance_status']);
        $this->assertSame('Đi muộn: Bus delay', $item['attendance_reason']);
    }

    public function testKeepingTheStoredTimesKeepsTheStoredStatus(): void
    {
        $checkIn = $this->lateCheckInWithReason();
        $action = app(OverlayReconciliationExportDataAction::class);

        $item = $action->overlay($action->mapCheckIn($checkIn), $this->row($checkIn, null), $checkIn);

        $this->assertSame('Đi muộn (30 phút)', $item['attendance_status']);
        $this->assertSame('Đi muộn: Bus delay', $item['attendance_reason']);
    }

    private function lateCheckInWithReason(): CheckIn
    {
        $seeded = $this->makeEmployeeWithCheckIn($this->makeWorkplace(), [], '2026-08-25', [
            'check_in_time' => '2026-08-25 21:30:00',
            'check_out_time' => '2026-08-26 06:00:00',
        ]);
        $seeded['checkIn']->update(['late_reason' => 'Bus delay']);

        return $seeded['checkIn']->refresh();
    }

    private function exportRow(CheckIn $checkIn, string $amazonCheckIn): array
    {
        $action = app(OverlayReconciliationExportDataAction::class);

        return $action->overlay($action->mapCheckIn($checkIn), $this->row($checkIn, $amazonCheckIn), $checkIn);
    }

    private function row(CheckIn $checkIn, ?string $amazonCheckIn): ReconciliationRow
    {
        return new ReconciliationRow([
            'check_in_id' => $checkIn->id,
            'external_check_in' => $amazonCheckIn,
            'external_check_out' => '2026-08-26 06:00:00',
            'decision_check_in' => $amazonCheckIn === null ? null : ReconciliationDecision::Amazon->value,
            'decision_check_out' => null,
            'decision_break' => null,
        ]);
    }
}
