<?php

declare(strict_types=1);

namespace Numra;

/**
 * The error taxonomy, ported from @getnumra/core so the two families agree.
 *
 * Catch on `getCode()`, never on the message. openapi.yaml says it plainly:
 * the code is the stable surface, the message is written for humans and
 * changes without notice. An SDK that exposes only a message forces every
 * integrator to string-match, and then a copy edit on our side breaks their
 * checkout.
 *
 * Codes are the API's own, plus three this client raises itself:
 *
 *   NETWORK_ERROR  nobody answered — DNS, reset socket. Distinct from every
 *                  API code, because those mean "Numra said no" and this means
 *                  "nobody said anything". A caller deciding whether to ship
 *                  the parcel anyway must be able to tell them apart.
 *   TIMEOUT        we gave up waiting.
 *   SERVER_ERROR   a 5xx, or a body we could not parse.
 */
final class NumraError extends \RuntimeException
{
    /** Every code this library can raise. openapi.yaml plus the three above. */
    public const CODES = [
        'LICENSE_MISSING',
        'LICENSE_INVALID',
        'LICENSE_EXPIRED',
        'LICENSE_BOUND',
        'COUNTRY_NOT_ALLOWED',
        'INVALID_PAYLOAD',
        'RATE_LIMITED',
        'QUOTA_EXCEEDED',
        'ENDPOINT_NOT_FOUND',
        'NETWORK_ERROR',
        'TIMEOUT',
        'SERVER_ERROR',
    ];

    public function __construct(
        /** One of self::CODES. Switch on this. */
        public readonly string $errorCode,
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $docsUrl = null,
        /** The parsed body, when there was one. Never contains the credential. */
        public readonly ?array $body = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** True when trying again later could plausibly succeed. */
    public function isRetryable(): bool
    {
        return \in_array(
            $this->errorCode,
            ['NETWORK_ERROR', 'TIMEOUT', 'SERVER_ERROR', 'RATE_LIMITED'],
            true,
        );
    }

    /** True when the credential is the problem and retrying will never help. */
    public function isAuthError(): bool
    {
        return \in_array(
            $this->errorCode,
            ['LICENSE_MISSING', 'LICENSE_INVALID', 'LICENSE_EXPIRED', 'LICENSE_BOUND'],
            true,
        );
    }

    /** True when you are out of quota — retryable, but not today. */
    public function isQuotaError(): bool
    {
        return $this->errorCode === 'QUOTA_EXCEEDED';
    }
}
