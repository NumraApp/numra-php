<?php

declare(strict_types=1);

namespace Numra;

/** The default transport. ext-curl only; no HTTP library is pulled in. */
final class CurlTransport implements Transport
{
    public function post(string $url, string $body, array $headers, float $timeoutSeconds): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new NumraError('NETWORK_ERROR', 'Could not initialise cURL.');
        }

        $out = [];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_map(
                static fn (string $k, string $v): string => "$k: $v",
                array_keys($headers),
                array_values($headers),
            ),
            /* Milliseconds, so a sub-second timeout is expressible. The
               connect timeout is separate and shorter: a host that will not
               answer at all should not eat the whole budget. */
            CURLOPT_TIMEOUT_MS => (int) round($timeoutSeconds * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round(min($timeoutSeconds, 5.0) * 1000),
            /* Never negotiable. This request carries a credential that reads a
               shared fraud ledger. */
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($_ch, string $line) use (&$out): int {
                $len = \strlen($line);
                $parts = explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $out[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $len;
            },
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            /* A timeout is not a network failure and must not be reported as
               one: "we gave up waiting" and "nobody is there" lead to
               different decisions about an order in hand. */
            /* 28 rather than the constant: PHP spells it CURLE_OPERATION_TIMEOUTED
               (sic) and referencing the other spelling is a fatal error on 8.x. */
            $timedOut = $errno === 28;

            throw new NumraError(
                $timedOut ? 'TIMEOUT' : 'NETWORK_ERROR',
                $timedOut
                    ? sprintf('Numra did not answer within %.0fms.', $timeoutSeconds * 1000)
                    : 'Could not reach Numra: ' . ($error !== '' ? $error : 'unknown network error'),
            );
        }

        return ['status' => $status, 'headers' => $out, 'body' => (string) $raw];
    }
}
