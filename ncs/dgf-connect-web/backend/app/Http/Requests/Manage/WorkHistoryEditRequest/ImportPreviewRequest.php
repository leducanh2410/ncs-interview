<?php

namespace App\Http\Requests\Manage\WorkHistoryEditRequest;

use Illuminate\Foundation\Http\FormRequest;

class ImportPreviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Server-side enforcement point (Requirement 2.6) - the client-side file-picker
            // restriction is a UX convenience only, not the actual gate.
            'file' => ['bail', 'required', 'file', 'mimes:xlsx', 'max:2048'],
        ];
    }
}
