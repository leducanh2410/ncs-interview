<?php

namespace App\Enums;

enum ShiftPlanDraftAction: string
{
    use EnumTrait;

    // A planned new assignment that becomes a live Approved ShiftUser on finalize.
    case Add = 'add';

    // A planned change to an existing live ShiftUser. Stored as a draft overlay and applied
    // on finalize, using shift_user_id to replace the original live row during validation.
    case Update = 'update';

    // Removal of an existing live ShiftUser. Carried only as a transient payload action: Save
    // applies it to live immediately (rejects the ShiftUser) rather than persisting a draft row.
    case Remove = 'remove';
}
