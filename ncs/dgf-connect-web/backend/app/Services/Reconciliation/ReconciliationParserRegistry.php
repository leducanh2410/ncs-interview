<?php

namespace App\Services\Reconciliation;

use App\Enums\ErrorCode;

class ReconciliationParserRegistry
{
    /** @var array<string, ReconciliationSourceParser> */
    private array $parsers = [];

    /**
     * @param iterable<ReconciliationSourceParser> $parsers
     */
    public function __construct(iterable $parsers)
    {
        foreach ($parsers as $parser) {
            $this->parsers[$parser->source()] = $parser;
        }
    }

    public function has(string $source): bool
    {
        return isset($this->parsers[$source]);
    }

    /**
     * @return list<string>
     */
    public function sources(): array
    {
        return array_keys($this->parsers);
    }

    public function detect(string $absolutePath, string $exceptSource): ?string
    {
        foreach ($this->parsers as $source => $parser) {
            if ($source === $exceptSource || !$parser instanceof DetectsReconciliationFormat) {
                continue;
            }
            if ($parser->matchesFormat($absolutePath)) {
                return $source;
            }
        }

        return null;
    }

    public function get(string $source): ReconciliationSourceParser
    {
        if (!isset($this->parsers[$source])) {
            throw new ReconciliationFormatException(ErrorCode::ReconciliationUnknownSource->value, [
                'source' => $source,
            ]);
        }

        return $this->parsers[$source];
    }
}
