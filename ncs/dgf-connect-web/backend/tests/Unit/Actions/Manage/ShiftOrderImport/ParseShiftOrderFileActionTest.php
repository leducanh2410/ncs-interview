<?php

namespace Tests\Unit\Actions\Manage\ShiftOrderImport;

use App\Actions\Manage\ShiftOrderImport\ParseShiftOrderFileAction;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ParseShiftOrderFileActionTest extends TestCase
{
    private ParseShiftOrderFileAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = new ParseShiftOrderFileAction();
    }

    public function testParsesWorkplaceBlockWithPositionsAndShiftRow(): void
    {
        $parsed = ($this->action)($this->grid($this->sampleRows()), '2026.10');

        $this->assertSame([], $parsed['errors']);
        $this->assertCount(1, $parsed['blocks']);

        $block = $parsed['blocks'][0];
        $this->assertSame(5, $block['workplace_id']);
        $this->assertSame('Workplace A', $block['workplace_name']);
        $this->assertSame([4 => 1, 5 => 2, 6 => 3], $block['day_columns']);
        $this->assertCount(1, $block['groups']);

        $group = $block['groups'][0];
        $this->assertSame(['Position A', 'spot'], array_column($group['positions'], 'name'));
        $this->assertSame([4 => 3, 5 => 0, 6 => 1], $group['positions'][0]['counts']);
        $this->assertSame('22:00', $group['shift']['start_time']);
        $this->assertSame('06:00', $group['shift']['end_time']);
        $this->assertSame([4 => 5, 5 => 0, 6 => 1], $group['shift']['counts']);
    }

    public function testSkipsPositionRowWithoutAnyValue(): void
    {
        $parsed = ($this->action)($this->grid($this->sampleRows()), '2026.10');

        $names = array_column($parsed['blocks'][0]['groups'][0]['positions'], 'name');
        $this->assertNotContains('Empty position', $names);
    }

    public function testReadsMonthFromHeaderSerialDate(): void
    {
        $parsed = ($this->action)($this->grid($this->sampleRows()), 'no month here');

        $this->assertSame('2026-10', $parsed['month']);
    }

    public function testFallsBackToSheetNameWhenHeaderHasNoDate(): void
    {
        $rows = $this->sampleRows();
        $rows[0][3] = null;

        $parsed = ($this->action)($this->grid($rows), '2027.03');

        $this->assertSame('2027-03', $parsed['month']);
    }

    public function testReturnsNullMonthWhenNeitherSourceHasIt(): void
    {
        $rows = $this->sampleRows();
        $rows[0][3] = null;

        $parsed = ($this->action)($this->grid($rows), 'Sheet1');

        $this->assertNull($parsed['month']);
    }

    public function testKeepsShiftWithoutPositionRows(): void
    {
        $rows = [
            [null, null, 'ID', 46296, '1日'],
            [null, null, 5, 'Workplace A', '1日'],
            [null, null, null, null, '木'],
            [null, null, null, '18:00〜21:30', 4],
            [null, null, null, null, null],
            [null, '注文合計(人)', null, null, 4],
        ];

        $parsed = ($this->action)($this->grid($rows), '2026.10');

        $group = $parsed['blocks'][0]['groups'][0];
        $this->assertSame([], $group['positions']);
        $this->assertSame([4 => 4], $group['shift']['counts']);
    }

    public function testReportsInvalidShiftTime(): void
    {
        $rows = $this->sampleRows();
        $rows[7][3] = '22:00 〜 xx:00';

        $parsed = ($this->action)($this->grid($rows), '2026.10');

        $this->assertSame('invalid_shift_time', $parsed['errors'][0]['code']);
        $this->assertSame('D8', $parsed['errors'][0]['cell']);
    }

    public function testReportsNonNumericCount(): void
    {
        $rows = $this->sampleRows();
        $rows[4][4] = 'abc';

        $parsed = ($this->action)($this->grid($rows), '2026.10');

        $this->assertSame('invalid_number', $parsed['errors'][0]['code']);
        $this->assertSame('E5', $parsed['errors'][0]['cell']);
    }

    public function testReportsPositionsWithoutShiftRow(): void
    {
        $rows = $this->sampleRows();
        $rows[7][3] = null;

        $parsed = ($this->action)($this->grid($rows), '2026.10');

        $this->assertSame('positions_without_shift', $parsed['errors'][0]['code']);
        $this->assertSame('D5', $parsed['errors'][0]['cell']);
    }

    public function testReportsDuplicateShiftTimeInSameBlock(): void
    {
        $rows = $this->sampleRows();
        array_splice($rows, 8, 0, [[null, null, null, '22:00〜30:00', 1, 0, 0]]);

        $parsed = ($this->action)($this->grid($rows), '2026.10');

        $this->assertSame('duplicate_shift', $parsed['errors'][0]['code']);
    }

    public function testReportsMissingWorkplaceBlock(): void
    {
        $rows = [[null, null, 'ID', 46296, '1日']];

        $parsed = ($this->action)($this->grid($rows), '2026.10');

        $this->assertSame([['code' => 'no_workplace_block']], $parsed['errors']);
    }

    public function testStartsNewBlockOnNextWorkplaceHeader(): void
    {
        $rows = $this->sampleRows();
        $rows[] = [null, null, 6, 'Workplace B', '1日', '2日', '3日'];
        $rows[] = [null, null, null, null, '木', '金', '土'];
        $rows[] = [null, null, null, '09:00〜17:00', 2, 2, 2];

        $parsed = ($this->action)($this->grid($rows), '2026.10');

        $this->assertCount(2, $parsed['blocks']);
        $this->assertSame(6, $parsed['blocks'][1]['workplace_id']);
        $this->assertSame('09:00', $parsed['blocks'][1]['groups'][0]['shift']['start_time']);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function sampleRows(): array
    {
        return [
            [null, null, 'ID', 46296, '1日', '2日', '3日'],
            [null, '合計Key', null, null, '木', '金', '土'],
            [null, null, 5, 'Workplace A', '1日', '2日', '3日'],
            [null, null, null, null, '木', '金', '土'],
            [null, null, null, 'Position A', 3, 0, 1],
            [null, null, null, 'spot', 2, 0, 0],
            [null, null, null, 'Empty position', null, null, null],
            [null, null, null, "22:00\u{00A0}〜\u{00A0}30:00", 5, 0, 1],
            [null, null, null, null, null, null, null],
            [null, '注文合計(人)', null, null, 5, 0, 1],
        ];
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @return Collection<int, Collection<int, mixed>>
     */
    private function grid(array $rows): Collection
    {
        return collect($rows)->map(fn(array $row) => collect($row));
    }
}
