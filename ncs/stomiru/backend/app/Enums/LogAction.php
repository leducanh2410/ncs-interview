<?php

namespace App\Enums;

enum LogAction: string
{
    use EnumTrait;

    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case CSV_REGISTER = 'csv_register';
    case NEW_REGISTER = 'new_register';
    case CSV_UPDATE = 'csv_update';
    case STATUS_UPDATE = 'status_update';
    case ROLE_UPDATE = 'role_update';
    case CSV_OUTPUT = 'csv_output';
    case DOB_UPDATE = 'dob_update';
    case NAME_UPDATE = 'name_update';
}
