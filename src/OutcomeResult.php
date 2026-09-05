<?php

declare(strict_types=1);

namespace Numra;

final class OutcomeResult
{
    public function __construct(
        /**
         * False for an idempotent replay AND for a number that is no longer
         * tracked — `message` distinguishes them. Do not read this alone as
         * "it landed".
         */
        public readonly bool $recorded,
        public readonly bool $idempotent,
        public readonly string $phone,
        public readonly string $orderId,
        public readonly string $outcomeType,
        public readonly ?string $message,
    ) {
    }

    public static function fromArray(array $r): self
    {
        return new self(
            (bool) ($r['recorded'] ?? false),
            (bool) ($r['idempotent'] ?? false),
            (string) ($r['phone'] ?? ''),
            (string) ($r['order_id'] ?? ''),
            (string) ($r['outcome_type'] ?? ''),
            $r['message'] ?? null,
        );
    }
}
