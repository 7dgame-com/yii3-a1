<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The only outcomes a login-code consumer may receive from LoginCodeStore.
 */
enum LoginCodeLookupStatus: string
{
    case HIT = 'hit';
    case MISS = 'miss';
    case EXPIRED = 'expired';
    case MALFORMED = 'malformed';
    case UNAVAILABLE = 'unavailable';
}
