<?php

declare(strict_types=1);

namespace Numra\Tests;

use Numra\WebhookVerificationError;
use Numra\Webhooks;
use PHPUnit\Framework\TestCase;

final class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private const BODY = '{"id":"evt_1","event":"verification.flagged","data":{"phone":"+212600000000"}}';

    /** @return array<string, string> */
    private static function sign(string $body, ?int $ts = null, string $secret = self::SECRET): array
    {
        $ts ??= time();

        return [
            'Numra-Signature' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret),
            'Numra-Timestamp' => (string) $ts,
        ];
    }

    public function testACorrectlySignedPayloadVerifiesAndIsReturnedParsed(): void
    {
        $p = Webhooks::verify(self::BODY, self::sign(self::BODY), self::SECRET);
        self::assertSame('verification.flagged', $p['event']);
        self::assertSame('+212600000000', $p['data']['phone']);
    }

    public function testTheSchemeMatchesWhatThePlatformActuallySends(): void
    {
        /* A fixed vector, not a round trip through our own signer — that would
           pass even if both sides moved together and away from the platform.
           Same vector as the JS suite. */
        $body = '{"a":1}';
        $ts = 1700000000;
        $expected = hash_hmac('sha256', "1700000000.{$body}", self::SECRET);

        Webhooks::verify(
            $body,
            ['Numra-Signature' => "sha256=$expected", 'Numra-Timestamp' => (string) $ts],
            self::SECRET,
            nowSeconds: $ts,
        );
        $this->addToAssertionCount(1);
    }

    public function testATamperedBodyIsRejected(): void
    {
        $h = self::sign(self::BODY);
        $this->expectException(WebhookVerificationError::class);
        Webhooks::verify(str_replace('flagged', 'cleared', self::BODY), $h, self::SECRET);
    }

    public function testTheWrongSecretIsRejected(): void
    {
        $h = self::sign(self::BODY, null, 'whsec_someone_elses');
        $this->expectException(WebhookVerificationError::class);
        Webhooks::verify(self::BODY, $h, self::SECRET);
    }

    public function testAReSerialisedBodyCanNeverMatch(): void
    {
        /* The single most common way to implement this and still be wrong.
           json_encode(json_decode($x)) is not $x — key order, whitespace and
           unicode escaping all move — and the usual "fix" is to skip
           verification entirely. Proving it fails here is what earns the
           NUMRA_RAW_BODY_UNAVAILABLE branch in Handlers. */
        /* A body Numra could really send: a note with an accent, and the
           whitespace a serialiser chose. self::BODY happens to survive a
           round trip, which would have made this test pass while proving
           nothing. */
        $body = '{"id":"evt_9", "event":"verification.flagged","data":{"note":"Café refusé"}}';
        $h = self::sign($body);
        $round = json_encode(json_decode($body, true), JSON_THROW_ON_ERROR);

        self::assertNotSame($body, $round, 'the fixture must actually change on a round trip');
        self::assertTrue(Webhooks::isValid($body, $h, self::SECRET), 'raw bytes verify');
        self::assertFalse(Webhooks::isValid($round, $h, self::SECRET), 're-serialised does not');
    }

    public function testAReplayOutsideTheToleranceIsRejected(): void
    {
        $ts = time() - 3600;
        try {
            Webhooks::verify(self::BODY, self::sign(self::BODY, $ts), self::SECRET);
            self::fail('expected a WebhookVerificationError');
        } catch (WebhookVerificationError $e) {
            /* Without this a captured "not blacklisted" payload stays valid
               for ever. */
            self::assertSame('expired', $e->reason);
        }
    }

    public function testAReplayInsideTheToleranceIsAccepted(): void
    {
        $ts = time() - 120;
        Webhooks::verify(self::BODY, self::sign(self::BODY, $ts), self::SECRET);
        $this->addToAssertionCount(1);
    }

    public function testMissingHeadersAreNamedIndividually(): void
    {
        $h = self::sign(self::BODY);

        try {
            Webhooks::verify(self::BODY, ['Numra-Timestamp' => $h['Numra-Timestamp']], self::SECRET);
            self::fail('expected a WebhookVerificationError');
        } catch (WebhookVerificationError $e) {
            self::assertSame('missing_signature', $e->reason);
        }

        try {
            Webhooks::verify(self::BODY, ['Numra-Signature' => $h['Numra-Signature']], self::SECRET);
            self::fail('expected a WebhookVerificationError');
        } catch (WebhookVerificationError $e) {
            self::assertSame('missing_timestamp', $e->reason);
        }
    }

    public function testANonNumericTimestampIsItsOwnReason(): void
    {
        try {
            Webhooks::verify(self::BODY, [
                'Numra-Signature' => 'sha256=whatever',
                'Numra-Timestamp' => 'yesterday',
            ], self::SECRET);
            self::fail('expected a WebhookVerificationError');
        } catch (WebhookVerificationError $e) {
            self::assertSame('bad_timestamp', $e->reason);
        }
    }

    /** @return array<string, array{array<string, string|string[]>}> */
    public static function headerSpellings(): array
    {
        $ts = 1700000000;
        $sig = 'sha256=' . hash_hmac('sha256', "1700000000." . self::BODY, self::SECRET);

        return [
            'as Numra sends them' => [['Numra-Signature' => $sig, 'Numra-Timestamp' => (string) $ts]],
            'lower-cased by the framework' => [['numra-signature' => $sig, 'numra-timestamp' => (string) $ts]],
            'straight out of $_SERVER' => [['HTTP_NUMRA_SIGNATURE' => $sig, 'HTTP_NUMRA_TIMESTAMP' => (string) $ts]],
            'PSR-7 array values' => [['Numra-Signature' => [$sig], 'Numra-Timestamp' => [(string) $ts]]],
        ];
    }

    /**
     * @dataProvider headerSpellings
     *
     * @param array<string, string|string[]> $headers
     */
    public function testHeaderLookupSurvivesEverySpellingPhpHandsUs(array $headers): void
    {
        /* PHP gives you a different case depending on where you read from —
           $_SERVER, PSR-7, Swoole — and guessing two of the three silently
           fails on the third. */
        Webhooks::verify(self::BODY, $headers, self::SECRET, nowSeconds: 1700000000);
        $this->addToAssertionCount(1);
    }

    public function testASignatureThatMatchesButIsNotJsonIsStillRejected(): void
    {
        $body = 'not json';
        try {
            Webhooks::verify($body, self::sign($body), self::SECRET);
            self::fail('expected a WebhookVerificationError');
        } catch (WebhookVerificationError $e) {
            self::assertSame('invalid_signature', $e->reason);
        }
    }
}
