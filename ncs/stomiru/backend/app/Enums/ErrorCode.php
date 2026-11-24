<?php

namespace App\Enums;

enum ErrorCode: string
{
    /**********************************************************************
     * Common error codes (starts with E_)
     **********************************************************************/
    case BadRequest = 'E_BAD_REQUEST';
    case Unauthorized = 'E_UNAUTHORIZED';
    case Forbidden = 'E_FORBIDDEN';
    case NotFound = 'E_NOT_FOUND';
    case MethodNotAllowed = 'E_METHOD_NOT_ALLOWED';
    case UnprocessableEntity = 'E_UNPROCESSABLE_ENTITY';
    case InternalServerError = 'E_INTERNAL_SERVER_ERROR';
    case Throttle = 'E_TOO_MANY_ATTEMPTS';

    /**********************************************************************
     * Authentication error codes (starts with AUTH_)
     **********************************************************************/
    case AuthInvalidToken = 'AUTH_INVALID_TOKEN';
    case AuthInvalidCredentials = 'AUTH_INVALID_CREDENTIALS';
    case AuthUserDisabled = 'AUTH_USER_DISABLED';
}
