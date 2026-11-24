<?php

namespace App\Services\AdminLeave;

use App\Contracts\HolidayProviderContract;
use Yasumi\Holiday;
use Yasumi\Provider\Japan;
use Yasumi\Yasumi;

/**
 * The single seam onto `azuyalabs/yasumi`. Nothing else in the app references the library, so a
 * future switch of holiday source only touches this class.
 */
class YasumiHolidayProvider implements HolidayProviderContract
{
    private const LOCALE = 'ja_JP';

    /**
     * @return array<string, string>
     */
    public function holidaysForYear(int $year): array
    {
        $provider = Yasumi::create(Japan::class, $year, self::LOCALE);

        $holidays = [];
        foreach ($provider->getHolidays() as $holiday) {
            /** @var Holiday $holiday */
            $holidays[$holiday->format('Y-m-d')] = $holiday->getName();
        }

        ksort($holidays);

        return $holidays;
    }
}
