<?php

namespace App\Actions\Admin\Dashboard;

class FormatKpisResponseAction
{
    public function __invoke(array $data): array
    {
        foreach ($data['daily_check_ins']['rows'] as &$row) {
            $row['by_workplace'] = (object) $row['by_workplace'];
        }
        unset($row);

        return $data;
    }
}
