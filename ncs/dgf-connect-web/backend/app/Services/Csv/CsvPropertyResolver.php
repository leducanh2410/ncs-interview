<?php

namespace App\Services\Csv;

use App\Enums\Gender;
use App\Enums\JapaneseLevel;
use App\Enums\Nationality;
use App\Enums\ResidenceStatus;
use App\Models\User;
use App\Models\UserInfo;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;

class CsvPropertyResolver
{
    public function __construct(private readonly CsvPropertyRegistry $registry) {}

    public function resolve(User $user, string $key): string
    {
        $definition = $this->registry->get($key);
        if ($definition === null) {
            throw new InvalidArgumentException("Unknown CSV property key: {$key}");
        }

        $value = $this->readValue($user, $definition);
        if ($value === null || $value === '') {
            return '';
        }

        return match (true) {
            $definition->type === CsvPropertyDefinition::TYPE_DATE => $this->formatDate($value),
            $definition->type === CsvPropertyDefinition::TYPE_BOOLEAN => $this->formatBoolean($value),
            $definition->isEnum() => $this->formatEnum($definition->type, (string) $value),
            default => (string) $value,
        };
    }

    private function readValue(User $user, CsvPropertyDefinition $definition): mixed
    {
        if ($definition->source === CsvPropertyDefinition::SOURCE_USER) {
            return $user->{$definition->column};
        }

        $info = $user->info;

        if ($info === null) {
            return null;
        }

        return match ($definition->column) {
            'residence_number', 'residence_expiry_date', 'residence_status' => $this->resolveLatestResidenceCard(
                $info,
                $definition->column
            ),
            default => $info->{$definition->column},
        };
    }

    private function resolveLatestResidenceCard(UserInfo $info, string $field): mixed
    {
        $cards = [
            1 => [
                'date' => $info->residence_expiry_date_1,
                'number' => $info->residence_number_1,
                'status' => $info->residence_status_1,
            ],
            2 => [
                'date' => $info->residence_expiry_date_2,
                'number' => $info->residence_number_2,
                'status' => $info->residence_status_2,
            ],
            3 => [
                'date' => $info->residence_expiry_date_3,
                'number' => $info->residence_number_3,
                'status' => $info->residence_status_3,
            ],
        ];

        $latestIndex = null;
        $latestDate = null;

        foreach ($cards as $index => $card) {
            if ($card['date'] !== null && $card['date'] !== '') {
                $date = Carbon::parse($card['date']);
                if ($latestDate === null || $date->gte($latestDate)) {
                    $latestDate = $date;
                    $latestIndex = $index;
                }
            }
        }

        if ($latestIndex === null) {
            foreach ($cards as $index => $card) {
                if (
                    ($card['number'] !== null && $card['number'] !== '') ||
                    ($card['status'] !== null && $card['status'] !== '')
                ) {
                    $latestIndex = $index;
                    break;
                }
            }
        }

        if ($latestIndex === null) {
            return null;
        }

        return match ($field) {
            'residence_number' => $cards[$latestIndex]['number'],
            'residence_expiry_date' => $cards[$latestIndex]['date'],
            'residence_status' => $cards[$latestIndex]['status'],
            default => null,
        };
    }

    private function formatDate(mixed $value): string
    {
        $date = $this->parseDate($value);

        return $date !== '' ? '="' . $date . '"' : '';
    }

    private function parseDate(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y/m/d');
        }

        if (is_string($value)) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', mb_substr($value, 0, 10));
            if ($parsed instanceof DateTimeInterface) {
                return $parsed->format('Y/m/d');
            }
            try {
                return (new \DateTimeImmutable($value))->format('Y/m/d');
            } catch (\Exception) {
                return '';
            }
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('Y/m/d');
        }

        return '';
    }

    private function formatBoolean(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'はい' : 'いいえ';
        }

        if (is_int($value)) {
            return $value === 1 ? 'はい' : 'いいえ';
        }

        if (is_string($value)) {
            return $value === '1' || mb_strtolower($value) === 'true' ? 'はい' : 'いいえ';
        }

        return '';
    }

    private function formatEnum(string $enumClass, string $value): string
    {
        return match ($enumClass) {
            Nationality::class => Nationality::toCrossStaffString($value),
            ResidenceStatus::class => ResidenceStatus::toCrossStaffString($value),
            Gender::class => match ($value) {
                Gender::Male->value => '男性',
                Gender::Female->value => '女性',
                Gender::Other->value => 'その他',
                default => '',
            },
            JapaneseLevel::class => match ($value) {
                JapaneseLevel::N1->value => 'N1',
                JapaneseLevel::N2->value => 'N2',
                JapaneseLevel::N3->value => 'N3',
                JapaneseLevel::N4->value => 'N4',
                JapaneseLevel::N5->value => 'N5',
                JapaneseLevel::None->value => 'なし',
                default => '',
            },
            default => $value,
        };
    }
}
