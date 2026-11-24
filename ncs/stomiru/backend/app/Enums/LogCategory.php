<?php

namespace App\Enums;

enum LogCategory: string
{
    use EnumTrait;

    case EMPLOYEE_MANAGEMENT = 'employee_management';
    case COMPANY_MANAGEMENT = 'company_management';
    case STRESS_CHECK_MANAGEMENT = 'stress_check_management';
    case EXAMINEE_LIST = 'examinee_list';
    case PROFILE_EDIT = 'profile_edit';
}
