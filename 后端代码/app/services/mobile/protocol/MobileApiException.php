<?php

declare(strict_types=1);

namespace app\services\mobile\protocol;

use RuntimeException;

/** A contract-level failure that the mobile transport can render safely. */
final class MobileApiException extends RuntimeException
{
    private string $kind;
    private string $mobileCode;
    private string $fieldPath;
    private ?string $sessionEndCause;

    private function __construct(string $kind, string $code, string $message, string $fieldPath = '', ?string $sessionEndCause = null)
    {
        parent::__construct($message, 0);
        $this->kind = $kind;
        $this->fieldPath = $fieldPath;
        $this->sessionEndCause = $sessionEndCause;
        $this->mobileCode = $code;
    }

    public static function protocol(string $code, string $message, string $fieldPath = ''): self
    {
        return new self('protocol', $code, $message, $fieldPath);
    }

    public static function business(string $code, string $message, ?string $sessionEndCause = null): self
    {
        return new self('business', $code, $message, '', $sessionEndCause);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function mobileCode(): string
    {
        return $this->mobileCode;
    }

    public function fieldPath(): string
    {
        return $this->fieldPath;
    }

    public function sessionEndCause(): ?string
    {
        return $this->sessionEndCause;
    }
}
