<?php

namespace Tests\Concerns;

use App\Models\User;

trait ResetsUserDocumentInfo
{
    /**
     * UserFactory seeds user_infos with random document data (residence slot 1,
     * passport, ...). Clear it so tests control the document fields exactly.
     */
    protected function resetUserDocumentInfo(User $user): void
    {
        $user->info->update([
            'residence_number_1' => null,
            'residence_expiry_date_1' => null,
            'residence_status_1' => null,
            'residence_number_2' => null,
            'residence_expiry_date_2' => null,
            'residence_status_2' => null,
            'residence_number_3' => null,
            'residence_expiry_date_3' => null,
            'residence_status_3' => null,
            'passport_number' => null,
            'passport_expiry_date' => null,
            'student_card_expiry_date' => null,
            'phone' => null,
            'postal_code' => null,
        ]);
    }
}
