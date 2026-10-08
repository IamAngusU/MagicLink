<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Mail;

use RuntimeException;
use Throwable;

final class MailTransportException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        public readonly bool $retryable,
        public readonly ?int $transportCode = null,
        ?Throwable $previous = null,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $category)) {
            throw new \InvalidArgumentException('Mail error category is invalid.');
        }
        $code = $transportCode === null ? '' : ' (' . $transportCode . ')';
        parent::__construct('Mail transport failed: ' . $category . $code . '.', $transportCode ?? 0, $previous);
    }

    public static function configuration(string $category = 'configuration', ?Throwable $previous = null): self
    {
        return new self($category, false, null, $previous);
    }

    public static function transient(string $category, ?Throwable $previous = null): self
    {
        return new self($category, true, null, $previous);
    }

    public static function smtp(int $code): self
    {
        return new self($code >= 400 && $code < 500 ? 'smtp_transient' : 'smtp_permanent', $code >= 400 && $code < 500, $code);
    }
}
