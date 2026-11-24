<?php

namespace Tests\Unit\Services\Reconciliation;

use App\Services\Reconciliation\AmazonKadouParser;
use App\Services\Reconciliation\CanonicalRow;
use App\Services\Reconciliation\DetectsReconciliationFormat;
use App\Services\Reconciliation\ReconciliationFormatException;
use App\Services\Reconciliation\ReconciliationParserRegistry;
use App\Services\Reconciliation\ReconciliationSourceParser;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReconciliationParserRegistryTest extends TestCase
{
    public function testUnknownSourceErrors(): void
    {
        $registry = new ReconciliationParserRegistry([new AmazonKadouParser()]);

        $this->expectException(ReconciliationFormatException::class);
        $registry->get('not_a_source');
    }

    public function testFakeSecondParserCanBeBound(): void
    {
        $fake = new class implements ReconciliationSourceParser {
            public function source(): string
            {
                return 'fake_test';
            }

            public function parse(string $absolutePath): array
            {
                return [
                    'company_label' => 'fake',
                    'punch_rows' => [
                        new CanonicalRow(
                            'Fake User',
                            '2026-08-25',
                            Carbon::parse('2026-08-25 20:00:00'),
                            Carbon::parse('2026-08-26 06:00:00'),
                            60,
                            []
                        ),
                    ],
                    'skipped_non_punch_count' => 0,
                ];
            }
        };

        $registry = new ReconciliationParserRegistry([new AmazonKadouParser(), $fake]);

        $this->assertTrue($registry->has('fake_test'));
        $this->assertSame('fake', $registry->get('fake_test')->parse('/dev/null')['company_label']);
    }

    public function testDetectSkipsTheSelectedSourceAndParsersWithoutDetection(): void
    {
        $detecting = fn(string $source) => new class ($source) implements
            DetectsReconciliationFormat,
            ReconciliationSourceParser
        {
            public function __construct(private readonly string $name) {}

            public function source(): string
            {
                return $this->name;
            }

            public function parse(string $absolutePath): array
            {
                return ['company_label' => null, 'punch_rows' => [], 'skipped_non_punch_count' => 0];
            }

            public function matchesFormat(string $absolutePath): bool
            {
                return true;
            }
        };
        $plain = new class implements ReconciliationSourceParser {
            public function source(): string
            {
                return 'plain';
            }

            public function parse(string $absolutePath): array
            {
                return ['company_label' => null, 'punch_rows' => [], 'skipped_non_punch_count' => 0];
            }
        };

        $registry = new ReconciliationParserRegistry([$plain, $detecting('selected'), $detecting('other')]);

        $this->assertSame('other', $registry->detect('/dev/null', 'selected'));
        $this->assertNull(
            (new ReconciliationParserRegistry([$plain, $detecting('selected')]))->detect('/dev/null', 'selected')
        );
    }
}
