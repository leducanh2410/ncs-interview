<?php

namespace App\Actions\Workplace;

use App\Models\User;

/**
 * Resolve whether test workplaces (is_test) should be surfaced to a viewer.
 *
 * Test workplaces are visible when either:
 *   - the SHOW_TEST_WORKPLACES env flag is enabled, or
 *   - the viewer is itself a test admin (is_test = true), so test admins can
 *     always see and manage the test data they own regardless of the flag.
 */
class ShouldShowTestWorkplacesAction
{
    public function __invoke(User $user): bool
    {
        return (bool) config('app.show_test_workplaces') || (bool) $user->is_test;
    }
}
