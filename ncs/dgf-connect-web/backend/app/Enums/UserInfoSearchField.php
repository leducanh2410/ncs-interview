<?php

namespace App\Enums;

enum UserInfoSearchField: string
{
    use EnumTrait;

    case ResidenceNumber = 'residence_number';
    case PassportNumber = 'passport_number';
    case Phone = 'phone';
    case EmergencyContactPhone = 'emergency_contact_phone';
    case PostalCode = 'postal_code';
    case Prefecture = 'prefecture';
    case Address = 'address';
    case RegisteredLocation = 'registered_location';
}
