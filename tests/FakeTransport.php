<?php

declare(strict_types=1);

namespace Numra\Tests;

use Numra\NumraError;
use Numra\Transport;

/**
 * A scripted transport.
 *
 * The JS suite runs against a real socket, because a stubbed fetch would let a
 * broken AbortController pass. Here the equivalent risk lives in
 * CurlTransport, which is exercised separately in CurlTransportTest against a
 * real PHP built-in server; everything above the transport is deterministic,
 * so scripting it is honest and keeps the retry tests instant.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{url: string, body: string, headers: array<string,string>}> */
    public array $calls = [];

    /** @param list<array{status?: int, headers?: array<string,string>, body?: mixed}|NumraError> $responses */
    public function __construct(private array $responses)
    {
    }

    public function post(string $url, string $body, array $headers, float $timeoutSeconds): array
    {
        $this->calls[] = ['url' => $url, 'body' => $body, 'headers' => $headers];

        $next = array_shift($this->responses);
        if ($next === null) {
            throw new \LogicException('FakeTransport ran out of scripted responses at call ' . \count($this->calls));
        }
        if ($next instanceof NumraError) {
            throw $next;
        }

        return [
            'status' => $next['status'] ?? 200,
            'headers' => $next['headers'] ?? [],
            'body' => \is_string($next['body'] ?? null)
                ? $next['body']
                : json_encode($next['body'] ?? [], JSON_THROW_ON_ERROR),
        ];
    }
}
