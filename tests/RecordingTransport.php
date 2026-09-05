<?php

declare(strict_types=1);

namespace Numra\Tests;

use Numra\Transport;

/**
 * A transport that answers from a queue and records what it was asked to send.
 *
 * FakeTransport answers; this one also remembers. "Did anything reach the
 * wire?" is the assertion most of the hardening tests actually need, and it
 * has to be a fact rather than an inference — a validation bug that still
 * spends a billable lookup has not been fixed.
 */
final class RecordingTransport implements Transport
{
    /** @var list<array{url: string, body: string}> */
    public array $calls = [];

    /** @param list<array{status: int, body: string, headers: array<string,string>}> $queue */
    public function __construct(private array $queue = [])
    {
    }

    public function post(string $url, string $body, array $headers, float $timeoutSeconds): array
    {
        $this->calls[] = ['url' => $url, 'body' => $body];

        return array_shift($this->queue) ?? ['status' => 200, 'body' => '{}', 'headers' => []];
    }
}
