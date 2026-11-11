<?php
declare(strict_types=1);

namespace Lib\Install;

use RuntimeException;

// InstallRefusal carries a named refusal code. Every failure of the install core
// ends in one of these so the caller gets a stable code and a non-zero exit,
// never a swallowed error. The message never contains plan values that could be
// secrets (token, password); callers pass field names and counts only.
final class InstallRefusal extends RuntimeException
{
    private string $refusalCode;

    public function __construct(string $refusalCode, string $detail = '')
    {
        $this->refusalCode = $refusalCode;
        parent::__construct($refusalCode . ($detail !== '' ? ': ' . $detail : ''));
    }

    // code returns the stable machine-readable refusal name (for example diskMissing).
    public function refusalCode(): string
    {
        return $this->refusalCode;
    }
}
