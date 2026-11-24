<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $company_code
 * @property string $company_name
 * @property string $contact_first_name
 * @property string $contact_last_name
 * @property string $contact_email
 * @property string $contact_phone
 * @property int $max_manager_accounts
 * @property int $max_employee_accounts
 * @property string $prefecture
 * @property string $postal_code
 * @property string $address
 * @property string $building_name
 * @property \Carbon\Carbon $contract_start_date
 * @property \Carbon\Carbon $contract_end_date
 * @property string $status
 * @property string $note
 */
class Company extends AbstractModel
{
    use SoftDeletes;

    protected $guarded = [];

    public array $sortable = [
        'id',
        'company_code',
        'company_name',
        'contact_first_name',
        'contact_last_name',
        'status',
        'contract_start_date',
        'contract_end_date',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function stressCheckPeriods(): HasMany
    {
        return $this->hasMany(StressCheckPeriod::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(Log::class);
    }
}
