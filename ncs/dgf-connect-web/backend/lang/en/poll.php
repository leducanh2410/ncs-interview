<?php

return [
    'inactive' => 'This poll is not active.',
    'expired' => 'This poll has expired.',
    'not_eligible' => 'You are not eligible for this poll.',
    'already_responded' => 'You have already submitted your response.',
    'missing_candidates' => 'Answer must include all candidates.',
    'invalid_vote_value' => 'Vote value must be 1 (yes), -1 (no), or 0 (blank).',
    'exceeds_max_choice' => 'Number of selections exceeds the maximum allowed.',
    'invalid_choice' => 'Invalid choice selected.',
    'duplicate_choices' => 'Duplicate choices are not allowed.',
    'single_choice_required' => 'Only one choice is allowed.',
    'empty_answer' => 'Answer cannot be empty.',
    'invalid_options' => 'Invalid poll options.',
    'locked_field' => 'Cannot modify :field after responses exist.',
    'workplace_required' => 'A workplace must be selected for a workplace poll.',
    'target_users_required' => 'At least one target user must be selected.',
    'workplace_not_allowed' => 'A workplace can only be set on a workplace poll.',
    'recurring_not_allowed' => 'Only feedback polls that target a workplace can be recurring.',
    'has_responses' => 'Cannot delete a poll with existing responses.',
    'already_active' => 'This poll is already active.',
    'already_inactive' => 'This poll is already inactive.',
    'notification' => [
        'title' => 'Action Required',
        'content' => 'You have a new item to respond: :title',
        'new_user_content' => 'You have new items awaiting your response',
    ],
];
