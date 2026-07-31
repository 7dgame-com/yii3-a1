<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Raised when the white-label service cannot provide a usable response.
 *
 * The public controller deliberately maps every instance to a generic 503 so
 * upstream topology, credentials, and failure details are never exposed.
 */
final class WhiteLabelGatewayException extends \RuntimeException
{
}
