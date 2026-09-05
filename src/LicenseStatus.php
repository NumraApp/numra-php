<?php

declare(strict_types=1);

namespace Numra;

final class LicenseStatus
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $plan,
        /**
         * Null means UNLIMITED, and is passed through as null on purpose.
         * Coercing it to 0 reads as "no quota left" — the exact opposite.
         */
        public readonly ?int $dailyLimit,
        public readonly int $dailyUsed,
        public readonly bool $unlimited,
        public readonly ?string $expiresAt,
        public readonly ?string $renewUrl,
    ) {
    }

    public static function fromArray(array $r): self
    {
        return new self(
            (string) ($r['license_status'] ?? 'UNKNOWN'),
            $r['plan'] ?? null,
            \array_key_exists('daily_limit', $r) && $r['daily_limit'] !== null
                ? (int) $r['daily_limit']
                : null,
            (int) ($r['daily_used'] ?? 0),
            (bool) ($r['unlimited'] ?? false),
            $r['expires_at'] ?? null,
            $r['renew_url'] ?? null,
        );
    }
}
