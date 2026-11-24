<?php

namespace App\Enums;

enum ReconciliationSource: string
{
    use EnumTrait;

    case AmazonKadou = 'amazon_kadou';
    case YamatoStaffData = 'yamato_staff_data';
    case FactoryTimesheet = 'factory_timesheet';
}
