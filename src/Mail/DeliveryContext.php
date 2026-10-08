<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Mail;

use Closure;
use InvalidArgumentException;

final readonly class DeliveryContext
{
    private ?Closure $heartbeat;

    public function __construct(
        public string $messageId,
        ?callable $heartbeat = null,
    ) {
        if (
            strlen($messageId) > 254
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*@[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/D', $messageId)
        ) {
            throw new InvalidArgumentException('Delivery Message-ID is invalid.');
        }
        $this->heartbeat = $heartbeat === null ? null : Closure::fromCallable($heartbeat);
    }

    public function heartbeat(): void
    {
        if ($this->heartbeat !== null) {
            ($this->heartbeat)();
        }
    }
}
