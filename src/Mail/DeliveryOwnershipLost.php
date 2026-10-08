<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Mail;

use RuntimeException;

final class DeliveryOwnershipLost extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The outbox delivery lease is no longer owned by this worker.');
    }
}
