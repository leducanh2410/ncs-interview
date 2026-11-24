<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A stored evidence file. Served only through a time-limited URL minted per response, so there is
 * no persisted url column.
 *
 * @property int $id
 * @property int $admin_leave_request_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property bool $is_image
 * @property AdminLeaveRequest $adminLeaveRequest
 */
class AdminLeaveRequestAttachment extends AbstractModel
{
    protected $fillable = ['admin_leave_request_id', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes'];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    protected $appends = ['is_image'];

    public function adminLeaveRequest(): BelongsTo
    {
        return $this->belongsTo(AdminLeaveRequest::class);
    }

    /**
     * Images get a thumbnail and a lightbox on the web admin; PDFs get a link to a new tab. The
     * distinction is made from the stored MIME type rather than the file extension.
     */
    protected function isImage(): Attribute
    {
        return Attribute::get(fn() => Str::startsWith($this->mime_type, 'image/'));
    }
}
