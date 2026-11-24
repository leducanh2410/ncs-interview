<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApplicationAccountService
{
    public function submitApplicationAccount(array $params): Company
    {
        return DB::transaction(function () use ($params) {
            $data = array_merge($params, [
                'company_code' => Str::upper(Str::random(8)),
                'status' => CompanyStatus::Pending->value,
                'contract_start_date' => now(),
            ]);

            $company = Company::query()->create($data);

            return $company;
        });
    }
}
