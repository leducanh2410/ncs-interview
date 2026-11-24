<?php

namespace App\Http\Requests\User\DocumentStep;

use App\Enums\UserPaperStepKey;
use App\Support\Document\DocumentStepConfirmRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * @deprecated Confirming one step at a time cannot enforce that a residence status which
 *             pulls in further documents is submitted together with them. Kept so app
 *             versions released before the single submit keep working; new clients post
 *             to /user/document-steps/submit instead.
 */
class ConfirmDocumentStepRequest extends FormRequest
{
    public function rules(): array
    {
        return DocumentStepConfirmRules::forStep(UserPaperStepKey::from($this->route('step')), Auth::id());
    }
}
