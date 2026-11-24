<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminReportComment extends AbstractModel
{
    protected $guarded = [];

    public function translations(): HasMany
    {
        return $this->hasMany(AdminReportCommentTrans::class);
    }
}
