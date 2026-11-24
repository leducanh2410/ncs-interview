<?php

namespace App\Services\Csv;

class CsvPropertyDefinition
{
    public const SOURCE_USER = 'user';

    public const SOURCE_USER_INFO = 'user_info';

    public const TYPE_STRING = 'string';

    public const TYPE_DATE = 'date';

    public const TYPE_BOOLEAN = 'boolean';

    /**
     * @param  string  $type  One of TYPE_STRING, TYPE_DATE, TYPE_BOOLEAN, or a backed-enum class FQN.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $labelJa,
        public readonly string $source,
        public readonly string $column,
        public readonly string $type
    ) {}

    public function isEnum(): bool
    {
        return enum_exists($this->type);
    }
}
