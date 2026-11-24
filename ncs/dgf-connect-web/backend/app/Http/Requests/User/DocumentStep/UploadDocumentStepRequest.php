<?php

namespace App\Http\Requests\User\DocumentStep;

use App\Enums\ErrorCode;
use App\Enums\UserPaperStepKey;
use Illuminate\Validation\Validator;

class UploadDocumentStepRequest extends ErrorCodedFormRequest
{
    private const FILE_RULES = ['required', 'image', 'mimes:jpg,jpeg,png,bmp,webp', 'max:8192'];

    private const MAX_FILES_PER_TYPE = 5;

    public function rules(): array
    {
        $step = $this->step();
        $rules = ['files' => ['required', 'array']];

        foreach ($step->requiredTypes() as $type) {
            if ($step->allowsMultipleFilesPerType()) {
                $rules["files.{$type}"] = ['required', 'array', 'min:1', 'max:' . self::MAX_FILES_PER_TYPE];
                $rules["files.{$type}.*"] = self::FILE_RULES;
            } else {
                $rules["files.{$type}"] = self::FILE_RULES;
            }
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $files = $this->file('files');
            if (!is_array($files)) {
                return;
            }

            $unexpected = array_diff(array_keys($files), $this->step()->requiredTypes());
            foreach ($unexpected as $type) {
                $validator->errors()->add("files.{$type}", 'This paper type does not belong to this step.');
            }
        });
    }

    protected function errorCodeFor(string $attribute, string $rule, mixed $value): ErrorCode
    {
        $isFileSlot = $attribute !== 'files';

        return match (true) {
            $rule === 'Required',
            $rule === 'Min',
            $rule === 'Array' && !$isFileSlot
                => ErrorCode::UserPaperFileRequired,
            $rule === 'Uploaded' => ErrorCode::UserPaperFileUploadFailed,
            is_array($value) && $isFileSlot => ErrorCode::UserPaperFileTooMany,
            $rule === 'Max' => ErrorCode::UserPaperFileTooLarge,
            default => ErrorCode::UserPaperFileInvalidType,
        };
    }

    protected function errorCodeForCustomFailure(string $attribute): ErrorCode
    {
        return ErrorCode::UserPaperFileWrongStep;
    }

    private function step(): UserPaperStepKey
    {
        return UserPaperStepKey::from($this->route('step'));
    }
}
