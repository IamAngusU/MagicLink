<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Exception;

use RuntimeException;

final class RateLimited extends RuntimeException {}
