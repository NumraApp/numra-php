<?php

declare(strict_types=1);

namespace Numra\Tests;

use Numra\Numra;
use Numra\NumraError;
use PHPUnit\Framework\TestCase;

final class NumraTest extends TestCase
{
    private function client(array $responses, array $extra = []): array
    {
        $t = new FakeTransport($responses);
        $numra = new Numra(array_merge([
            'apiKey' => 'numra_test_key',
            'baseUrl' => 'https://api.example.test',
            'transport' => $t,
            /* Never actually sleep in a retry test. */
            'sleeper' => static function (int $ms): void {
            },
        ], $extra));

        return [$numra, $t];
    }

    public function testAMissingKeyFailsAtConstructionNotAtFirstCall(): void
    {
        $this->expectException(NumraError::class);
        $this->expectExceptionMessage('A Numra API key is required');
        new Numra(['apiKey' => '']);
    }

    public function testCheckMapsTheWireFormatAndKeepsTheRawBody(): void
    {
        [$numra] = $this->client([['body' => Fixtures::LOOKUP_OK]]);
        $c = $numra->check('0600000000');

        self::assertSame('HIGH', $c->riskLevel);
        self::assertSame(72, $c->riskScore);
        self::assertSame(28.0, $c->trustScore);
        self::assertTrue($c->isRated);
        self::assertSame('reactive', $c->customerStyle?->code);
        self::assertSame('Maroc Telecom', $c->carrierLabel);
        /* The untouched response stays reachable, so a field added
           server-side does not need an SDK release. */
        self::assertSame(68.4, $c->raw['risk_score_raw']);
    }

    public function testEveryRequestCarriesAuthCountryAndAVersionedUserAgent(): void
    {
        [$numra, $t] = $this->client([['body' => Fixtures::LOOKUP_OK]], ['integration' => 'laravel']);
        $numra->check('0600000000');

        $h = $t->calls[0]['headers'];
        self::assertSame('Bearer numra_test_key', $h['Authorization']);
        self::assertSame('MA', $h['X-Country']);
        /* We report which SDK versions are live in the
           field from this header, so it has to carry both numbers. */
        self::assertStringStartsWith('numra-php/' . Numra::VERSION . ' laravel php/', $h['User-Agent']);
        self::assertSame('https://api.example.test/v1/phone/lookup', $t->calls[0]['url']);
    }

    public function testUnsetOptionalsAreDroppedNotSentAsNull(): void
    {
        /* Parity with @numra/core: JSON.stringify drops undefined, so the API
           has never seen these keys. Including inside `context`. */
        [$numra, $t] = $this->client([['body' => Fixtures::LOOKUP_OK]]);
        $numra->check('0600000000', ['context' => ['orderTotal' => 250.0]]);

        $sent = json_decode($t->calls[0]['body'], true);
        self::assertSame(['phone', 'context'], array_keys($sent));
        /* assertEquals, not assertSame: json_encode writes 250.0 as `250`,
           exactly as JSON.stringify does, so it decodes back as an int. That
           is the parity we want — the API sees one number either way. */
        self::assertEquals(['order_total' => 250], $sent['context']);
    }

    public function testTheApiErrorCodeBecomesTheErrorCodeNotTheMessage(): void
    {
        [$numra] = $this->client([[
            'status' => 402,
            'body' => ['ok' => false, 'error' => 'QUOTA_EXCEEDED', 'message' => 'Daily limit reached'],
        ]]);

        try {
            $numra->check('0600000000');
            self::fail('expected a NumraError');
        } catch (NumraError $e) {
            /* Callers switch on the code. The message is written for humans
               and changes without notice. */
            self::assertSame('QUOTA_EXCEEDED', $e->errorCode);
            self::assertTrue($e->isQuotaError());
            self::assertSame('Daily limit reached', $e->getMessage());
        }
    }

    public function testAnAuthFailureIsFlaggedAndNeverRetried(): void
    {
        [$numra, $t] = $this->client([[
            'status' => 401,
            'body' => ['ok' => false, 'error' => 'LICENSE_EXPIRED', 'message' => 'expired'],
        ]]);

        try {
            $numra->check('0600000000');
            self::fail('expected a NumraError');
        } catch (NumraError $e) {
            self::assertTrue($e->isAuthError());
            self::assertFalse($e->isRetryable());
        }
        /* One call. Retrying a rejected credential is hammering with a key
           that will never work. */
        self::assertCount(1, $t->calls);
    }

    public function testA500IsRetriedAndTheEventualSuccessIsReturned(): void
    {
        [$numra, $t] = $this->client([
            ['status' => 500, 'body' => ['ok' => false, 'message' => 'boom']],
            ['body' => Fixtures::LOOKUP_OK],
        ]);

        self::assertSame('HIGH', $numra->check('0600000000')->riskLevel);
        self::assertCount(2, $t->calls);
    }

    public function testQuotaExceededIsNeverRetriedEvenThoughItArrivesAsA429(): void
    {
        /* The quota resets at midnight. Retrying inside the request turns one
           exhausted day into sustained hammering and never gets an answer. */
        [$numra, $t] = $this->client([
            ['status' => 429, 'body' => ['ok' => false, 'error' => 'QUOTA_EXCEEDED']],
            ['body' => Fixtures::LOOKUP_OK],
        ]);

        $this->expectException(NumraError::class);
        try {
            $numra->check('0600000000');
        } finally {
            self::assertCount(1, $t->calls);
        }
    }

    public function testRateLimitedIsRetriedAndRetryAfterWinsOverOurOwnBackoff(): void
    {
        $slept = [];
        [$numra, $t] = $this->client([
            ['status' => 429, 'headers' => ['retry-after' => '2'], 'body' => ['ok' => false, 'error' => 'RATE_LIMITED']],
            ['body' => Fixtures::LOOKUP_OK],
        ], ['sleeper' => static function (int $ms) use (&$slept): void {
            $slept[] = $ms;
        }]);

        $numra->check('0600000000');
        self::assertCount(2, $t->calls);
        /* The server knows more than our backoff curve does, so its floor is
           respected exactly — jitter on this path may only ADD, never
           subtract, or we would come back before we were told to. The spread
           exists so a fleet that all received the identical Retry-After does
           not wake in lockstep. */
        self::assertCount(1, $slept);
        self::assertGreaterThanOrEqual(2000, $slept[0]);
        self::assertLessThanOrEqual(3000, $slept[0]);
    }

    public function testANetworkFailureIsRetriedButKeepsItsOwnCode(): void
    {
        /* NETWORK_ERROR means nobody answered; every API code means Numra
           said no. A merchant deciding whether to ship the parcel anyway has
           to be able to tell them apart. */
        [$numra, $t] = $this->client([
            new NumraError('NETWORK_ERROR', 'connection reset'),
            new NumraError('NETWORK_ERROR', 'connection reset'),
            new NumraError('NETWORK_ERROR', 'connection reset'),
        ]);

        try {
            $numra->check('0600000000');
            self::fail('expected a NumraError');
        } catch (NumraError $e) {
            self::assertSame('NETWORK_ERROR', $e->errorCode);
        }
        /* maxRetries defaults to 2, so three attempts in total. */
        self::assertCount(3, $t->calls);
    }

    public function testDailyLimitNullSurvivesAsNullAndIsNeverCoercedToZero(): void
    {
        /* null means unlimited. Turning it into 0 reads as "no quota left" —
           the exact opposite. */
        [$numra] = $this->client([['body' => [
            'ok' => true, 'license_status' => 'ACTIVE', 'plan' => 'scale',
            'daily_limit' => null, 'daily_used' => 412, 'unlimited' => true,
        ]]]);

        $l = $numra->verifyLicense();
        self::assertNull($l->dailyLimit);
        self::assertTrue($l->unlimited);
        self::assertSame(412, $l->dailyUsed);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function incompleteOutcomes(): array
    {
        return [
            'no phone' => [['orderId' => 'A1', 'outcomeType' => 'DELIVERED'], 'a phone'],
            'no orderId' => [['phone' => '0600000000', 'outcomeType' => 'DELIVERED'], 'idempotency key'],
            'no outcomeType' => [['phone' => '0600000000', 'orderId' => 'A1'], 'an outcomeType'],
        ];
    }

    /**
     * @dataProvider incompleteOutcomes
     *
     * @param array<string, mixed> $input
     */
    public function testReportOutcomeRequiresTheWholeIdempotencyKey(array $input, string $says): void
    {
        /* Rejected before the request, so an incomplete key cannot become a
           duplicate row that quietly re-rates a customer. */
        [$numra, $t] = $this->client([]);

        try {
            $numra->reportOutcome($input);
            self::fail('expected a NumraError');
        } catch (NumraError $e) {
            self::assertSame('INVALID_PAYLOAD', $e->errorCode);
            self::assertStringContainsString($says, $e->getMessage());
        }
        self::assertCount(0, $t->calls);
    }

    public function testAnIdempotentReplayIsDistinguishableFromAFreshRecord(): void
    {
        [$numra] = $this->client([['body' => [
            'ok' => true, 'recorded' => false, 'idempotent' => true,
            'phone' => '+212600000000', 'order_id' => 'A1', 'outcome_type' => 'REFUSED_COD',
            'message' => 'Already recorded.',
        ]]]);

        $r = $numra->reportOutcome(['phone' => '0600000000', 'orderId' => 'A1', 'outcomeType' => 'REFUSED_COD']);
        self::assertFalse($r->recorded);
        self::assertTrue($r->idempotent);
        /* recorded=false also covers "no longer tracked". The message is what
           separates the two, so it has to survive. */
        self::assertSame('Already recorded.', $r->message);
    }
}
