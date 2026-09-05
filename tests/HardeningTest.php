<?php

declare(strict_types=1);

namespace Numra\Tests;

use Numra\Handlers;
use Numra\Numra;
use Numra\NumraError;
use PHPUnit\Framework\TestCase;

/**
 * Regressions from the pre-release audit, 4 September 2026.
 *
 * The first group is the most important test in this package. A fraud client
 * that answers "clean" when it did not get an answer is worse than one that is
 * down, because being down is visible.
 */
final class HardeningTest extends TestCase
{
    private function client(array $responses, ?array &$sent = null): Numra
    {
        $transport = new RecordingTransport($responses);
        $sent = &$transport->calls;

        return new Numra([
            'apiKey' => 'numra_test_key',
            'transport' => $transport,
            'sleeper' => static fn () => null,
        ]);
    }

    /** @dataProvider unparseableBodies */
    public function testATwoHundredWithAnUnparseableBodyIsNotACleanVerdict(string $body): void
    {
        /* This used to `return $json ?? []`, and PhoneCheck::fromArray([])
           defaults to verdict UNRATED, score 0, is_blacklisted false — so a
           blacklisted number came back clean, with no error and no log line. */
        $numra = $this->client(array_fill(0, 4, ['status' => 200, 'body' => $body, 'headers' => []]));

        $this->expectException(NumraError::class);
        try {
            $numra->check('0600000000');
        } catch (NumraError $e) {
            self::assertSame('SERVER_ERROR', $e->errorCode);
            throw $e;
        }
    }

    public static function unparseableBodies(): array
    {
        return [
            'a captive portal' => ['<html>login</html>'],
            'an empty body' => [''],
            'a bare string' => ['ok'],
        ];
    }

    public function testARealBodyStillResolves(): void
    {
        $numra = $this->client([[
            'status' => 200,
            'body' => '{"phone":"0600000000","verdict":"HIGH_RISK","is_blacklisted":true,"risk_score":91}',
            'headers' => [],
        ]]);
        $check = $numra->check('0600000000');
        self::assertSame('HIGH_RISK', $check->verdict);
        self::assertTrue($check->isBlacklisted);
    }

    /** @dataProvider retryAfterValues */
    public function testRetryAfterCannotParkACheckout(string $header): void
    {
        /* Unclamped, `Retry-After: 86400` was a 24-hour BLOCKING usleep inside
           an FPM worker, and max_execution_time does not count time asleep —
           a few hundred orders took the whole store offline. A negative value
           threw a raw ValueError past every documented catch (NumraError). */
        $slept = [];
        $transport = new RecordingTransport([
            ['status' => 500, 'body' => '{}', 'headers' => ['retry-after' => $header]],
            ['status' => 200, 'body' => '{"phone":"0600000000"}', 'headers' => []],
        ]);
        $numra = new Numra([
            'apiKey' => 'k',
            'transport' => $transport,
            'maxRetries' => 1,
            'sleeper' => static function (int $ms) use (&$slept): void { $slept[] = $ms; },
        ]);
        $numra->check('0600000000');

        self::assertGreaterThanOrEqual(0, $slept[0]);
        self::assertLessThanOrEqual(20000, $slept[0]);
    }

    public static function retryAfterValues(): array
    {
        return [['86400'], ['999999'], ['-100'], ['2']];
    }

    public function testAFourOhFourIsAnsweredOnceAndNamed(): void
    {
        $transport = new RecordingTransport(array_fill(0, 5, ['status' => 404, 'body' => '<html>', 'headers' => []]));
        $numra = new Numra([
            'apiKey' => 'k', 'transport' => $transport, 'maxRetries' => 3,
            'sleeper' => static fn () => null,
        ]);
        try {
            $numra->check('0600000000');
            self::fail('expected a NumraError');
        } catch (NumraError $e) {
            self::assertSame('ENDPOINT_NOT_FOUND', $e->errorCode);
            self::assertCount(1, $transport->calls, 'a 4xx must not be retried as a server fault');
        }
    }

    public function testAnOversizedPhoneIsRefusedBeforeAnythingIsSpent(): void
    {
        $transport = new RecordingTransport([]);
        $numra = new Numra(['apiKey' => 'k', 'transport' => $transport, 'sleeper' => static fn () => null]);
        $handlers = new Handlers($numra, authorize: static fn () => true);

        $res = $handlers->check(['phone' => str_repeat('6', 5000)]);
        self::assertSame(400, $res['status']);
        self::assertCount(0, $transport->calls);
    }

    /** @dataProvider badOutcomes */
    public function testOutcomeRefusesNonStrings(array $input): void
    {
        /* The blind (string) casts wrote the literal word "Array" into the
           ledger, and passed nested objects straight through to the wire. */
        $transport = new RecordingTransport([]);
        $numra = new Numra(['apiKey' => 'k', 'transport' => $transport, 'sleeper' => static fn () => null]);
        $handlers = new Handlers($numra, authorize: static fn () => true);

        $res = $handlers->outcome($input);
        self::assertSame(400, $res['status']);
        self::assertSame('INVALID_PAYLOAD', $res['body']['error']);
        self::assertCount(0, $transport->calls);
    }

    public static function badOutcomes(): array
    {
        return [
            'an array phone' => [['phone' => ['a', 'b'], 'orderId' => 'o', 'outcomeType' => 'D']],
            'an array orderId' => [['phone' => '06', 'orderId' => ['a'], 'outcomeType' => 'D']],
            'a nested currency' => [['phone' => '06', 'orderId' => 'o', 'outcomeType' => 'D', 'currency' => ['deep' => 1]]],
            'a missing outcomeType' => [['phone' => '06', 'orderId' => 'o']],
        ];
    }

    public function testAnEmptyWebhookBodyIsUnauthenticNotAMisconfiguration(): void
    {
        /* Folding "empty" in with "already consumed" let anyone on the
           internet produce a 500 plus a log line accusing the merchant's own
           setup — a way to talk someone into disabling verification. */
        $numra = $this->client([]);
        $lines = [];
        $handlers = new Handlers(
            $numra,
            authorize: static fn () => true,
            webhookSecret: 'whsec_test',
            log: static function (string $m) use (&$lines): void { $lines[] = $m; },
        );

        $res = $handlers->webhook('', []);
        self::assertSame(400, $res['status']);
        self::assertSame('missing_signature', $res['body']['error']);
        self::assertCount(0, $lines, 'an unauthentic request must not raise an alarm');
    }

    public function testAParsedWebhookBodyIsStillALoud500(): void
    {
        $numra = $this->client([]);
        $handlers = new Handlers(
            $numra, authorize: static fn () => true, webhookSecret: 'whsec_test',
            log: static fn () => null,
        );
        $res = $handlers->webhook(['already' => 'parsed'], []);
        self::assertSame(500, $res['status']);
        self::assertSame('NUMRA_RAW_BODY_UNAVAILABLE', $res['body']['error']);
    }

    public function testTheNotConfiguredDiagnosticIsSaidOncePerProcess(): void
    {
        /* The endpoint is public, so once per request meant a scanner filled
           the merchant's disk while they were mid-deploy. */
        $numra = $this->client([]);
        $lines = 0;
        $handlers = new Handlers($numra, log: static function () use (&$lines): void { $lines++; });

        for ($i = 0; $i < 50; $i++) {
            $res = $handlers->check(['phone' => '0600000000']);
            self::assertSame(500, $res['status']);
        }
        self::assertSame(1, $lines);
    }
}
