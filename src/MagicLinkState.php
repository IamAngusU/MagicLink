<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink;

enum MagicLinkState: string
{
    case Requested = 'requested';
    case Waiting = 'waiting';
    case Verified = 'verified';
    case Expired = 'expired';
    case Replayed = 'replayed';
    case RateLimited = 'rate_limited';
    case Denied = 'denied';
    case Failed = 'failed';
}
