<?php

namespace App\Services\SuperAdmin;

use App\Models\Company;
use App\Models\Log;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class LogService
{
    public function listLogs(array $conditions, Company $company): LengthAwarePaginator
    {
        $orderBy = '-created_at';
        if (! empty($conditions['sort'])) {
            $orderBy = $conditions['sort'];
        }

        if (! empty($conditions['created_at'])) {
            $range = explode(',', $conditions['created_at']);
            unset($conditions['created_at']);
            if (! empty($range[0])) {
                $conditions['start_date'] = $range[0];
            }
            if (! empty($range[1])) {
                $conditions['end_date'] = $range[1];
            }
        }

        return Log::getFilteredQuery($conditions, null, $orderBy)
            ->where('company_id', $company->id)
            ->paginate($conditions['page_size'] ?? 10);
    }

    public function create(array $data): Log
    {
        /** @var User|null $user */
        $user = Auth::user();

        $logData = array_merge([
            'actor_id' => $user?->id,
            'actor_name' => $user?->first_name.' '.$user?->last_name,
            'actor_role' => $user?->getRolesSimple()?->pluck('name')->toArray() ?? [],
            'company_id' => $user?->company_id,
        ], $data);

        return Log::create($logData);
    }
}
