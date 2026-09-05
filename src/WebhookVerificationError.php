<?php

declare(strict_types=1);

namespace Numra;

/**
 * Why a webhook was not accepted.
 *
 * Separate from NumraError because these are not API failures — nothing was
 * called. The `reason` is the stable surface and matches @getnumra/core exactly:
 * missing_signature, missing_timestamp, bad_timestamp, expired,
 * invalid_signature, body_not_raw.
 */
final class WebhookVerificationError extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
