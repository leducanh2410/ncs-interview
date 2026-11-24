<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminReportCommentTrans extends AbstractModel
{
    protected $guarded = [];

    public function adminReportComment(): BelongsTo
    {
        return $this->belongsTo(AdminReportComment::class);
    }
}
