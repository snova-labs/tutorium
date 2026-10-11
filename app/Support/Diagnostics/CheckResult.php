<?php

declare(strict_types=1);

namespace App\Support\Diagnostics;

/**
 * One thing that was checked, and what was found.
 *
 * A warning is something to look at that does not make the deployment wrong — a failed job
 * waiting, say. Only a failure means this release should not be serving traffic.
 */
final class CheckResult
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    private function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $detail,
    ) {}

    public static function ok(string $name, string $detail): self
    {
        return new self($name, self::OK, $detail);
    }

    public static function warn(string $name, string $detail): self
    {
        return new self($name, self::WARN, $detail);
    }

    public static function fail(string $name, string $detail): self
    {
        return new self($name, self::FAIL, $detail);
    }

    public function failed(): bool
    {
        return $this->status === self::FAIL;
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['check' => $this->name, 'status' => $this->status, 'detail' => $this->detail];
    }
}
