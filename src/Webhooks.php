<?php

declare(strict_types=1);

namespace Numra;

/**
 * Verifying a webhook from Numra.
 *
 * The scheme, normatively, from packages/shared/openapi.yaml:
 *
 *     Numra-Signature: sha256=<hex>
 *     Numra-Timestamp: <unix seconds>
 *     hex = HMAC-SHA256(secret, "{timestamp}.{rawBody}")
 *
 * Three ways to implement this and still be wrong, all of which pass a
 * happy-path test:
 *
 *   1. Verifying a re-serialised body. json_encode(json_decode($x)) is not
 *      $x — key order, whitespace, unicode escaping and number formatting all
 *      move — so every signature fails, and the usual "fix" is for the
 *      integrator to give up and skip verification entirely. So this rejects
 *      anything that is not a raw string, with a message saying what to do.
 *   2. Comparing with ==. That returns on the first differing byte, leaking
 *      the signature one character at a time to anyone who can time the
 *      response. hash_equals exists for this.
 *   3. Ignoring the timestamp. The signature then stays valid for ever, so a
 *      captured "not blacklisted" payload can be replayed at will.
 *
 * Ported from @getnumra/core's webhooks.js, which is the reference.
 */
final class Webhooks
{
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * A genuine case-insensitive scan, not `$h[$exact] ?? $h[$lower]`.
     * PHP hands you headers in whatever case the framework chose —
     * `HTTP_NUMRA_SIGNATURE` from $_SERVER, `Numra-Signature` from a PSR-7
     * request, `numra-signature` from Swoole — and guessing two of the three
     * silently fails on the third.
     *
     * @param array<string, string|string[]> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        $want = strtolower($name);
        foreach ($headers as $k => $v) {
            $k = strtolower((string) $k);
            /* $_SERVER spelling: HTTP_NUMRA_SIGNATURE */
            if (str_starts_with($k, 'http_')) {
                $k = str_replace('_', '-', substr($k, 5));
            }
            if ($k === $want) {
                return \is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
            }
        }

        return null;
    }

    /**
     * Verify a Numra webhook and return its parsed payload.
     *
     * $rawBody must be the exact bytes received — `file_get_contents('php://input')`,
     * not `$_POST` and not a re-encoded array.
     *
     * @param  array<string, string|string[]> $headers
     * @return array<string, mixed> the parsed payload
     *
     * @throws WebhookVerificationError if the request is not authentic
     */
    public static function verify(
        string $rawBody,
        array $headers,
        string $secret,
        ?int $toleranceSeconds = null,
        ?int $nowSeconds = null,
    ): array {
        $signature = self::header($headers, 'numra-signature');
        $timestamp = self::header($headers, 'numra-timestamp');

        if ($signature === null || $signature === '') {
            throw new WebhookVerificationError('missing_signature', 'Numra-Signature header is missing.');
        }
        if ($timestamp === null || $timestamp === '') {
            throw new WebhookVerificationError('missing_timestamp', 'Numra-Timestamp header is missing.');
        }
        if (!is_numeric($timestamp)) {
            throw new WebhookVerificationError('bad_timestamp', "Numra-Timestamp is not a number: $timestamp");
        }

        /* Normalised the way JavaScript's Number() would, so a padded or
           float-spelled header signs identically in both families. */
        $tsNum = $timestamp + 0;
        $ts = \is_float($tsNum) && floor($tsNum) === $tsNum ? (string) (int) $tsNum : (string) $tsNum;

        $tolerance = $toleranceSeconds ?? self::DEFAULT_TOLERANCE_SECONDS;
        $now = $nowSeconds ?? time();
        $drift = (int) abs($now - (int) $tsNum);
        if ($drift > $tolerance) {
            throw new WebhookVerificationError(
                'expired',
                "Timestamp is {$drift}s from now, outside the {$tolerance}s tolerance. "
                . 'This is replay protection, not a clock bug — check the server clock before widening it.',
            );
        }

        $expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $rawBody, $secret);

        /* hash_equals, never ==. A byte-by-byte compare that returns early
           leaks the signature one character at a time to anyone who can time
           the response. It is also length-safe, unlike Node's
           timingSafeEqual, which throws on mismatched lengths. */
        if (!hash_equals($expected, $signature)) {
            throw new WebhookVerificationError(
                'invalid_signature',
                'Signature does not match. Verify against the RAW body, and check the signing secret belongs to this endpoint.',
            );
        }

        $payload = json_decode($rawBody, true);
        if (!\is_array($payload)) {
            throw new WebhookVerificationError('invalid_signature', 'Signature matched but the body is not valid JSON.');
        }

        return $payload;
    }

    /**
     * Non-throwing variant, for callers that prefer a branch to a try/catch.
     *
     * @param array<string, string|string[]> $headers
     */
    public static function isValid(
        string $rawBody,
        array $headers,
        string $secret,
        ?int $toleranceSeconds = null,
        ?int $nowSeconds = null,
    ): bool {
        try {
            self::verify($rawBody, $headers, $secret, $toleranceSeconds, $nowSeconds);

            return true;
        } catch (WebhookVerificationError) {
            return false;
        }
    }
}
