<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Exception;

use RuntimeException;

final class RateLimited extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter = 60)
    {
        parent::__construct($message);
    }
}
