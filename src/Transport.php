<?php

declare(strict_types=1);

namespace Numra;

/**
 * How the client reaches the network.
 *
 * An interface rather than a hard cURL call so a host that already has a PSR-18
 * client, a proxy, or its own instrumentation can supply one — and so the
 * timeout and retry paths can be tested without waiting on real sockets.
 *
 * An implementation MUST throw NumraError with code TIMEOUT or NETWORK_ERROR
 * rather than returning a status of 0. The two are different answers to
 * "should I ship this parcel anyway", and flattening them into a 5xx loses
 * that.
 */
interface Transport
{
    /**
     * @param  array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     *
     * @throws NumraError
     */
    public function post(string $url, string $body, array $headers, float $timeoutSeconds): array;
}
