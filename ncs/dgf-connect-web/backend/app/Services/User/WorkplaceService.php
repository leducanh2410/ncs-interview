<?php

namespace App\Services\User;

use App\Enums\ErrorCode;
use App\Models\Workplace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class WorkplaceService
{
    public function getWorkplaceList(): Collection
    {
        // Test workplaces are only visible to test employees, and normal
        // workplaces only to normal employees, keeping test data isolated.
        return Workplace::query()->where('is_test', (bool) Auth::user()->is_test)->with('images')->get();
    }

    public function getWorkplaceInstructions(Workplace $workplace): array
    {
        if ($workplace->users()->where('users.id', Auth::id())->doesntExist()) {
            return [
                'error' => ErrorCode::Forbidden,
                'status' => 403,
            ];
        }
        $workplace->load('instructions');

        return [
            'error' => null,
            'data' => $workplace,
        ];
    }
}
