<?php

namespace App\Services\Admin;

use App\Models\SystemConfig;

class ConfigService
{
    public function getAllConfigs()
    {
        return SystemConfig::query()->first();
    }

    public function updateConfigs(array $params)
    {
        return SystemConfig::query()->updateOrCreate([], $params);
    }
}
