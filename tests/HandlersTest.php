<?php

declare(strict_types=1);

namespace Numra\Tests;

use Numra\Handlers;
use Numra\Numra;
use PHPUnit\Framework\TestCase;

/**
 * The PHP half of the guarantee @getnumra/core's server.js makes in JS: deny by
 * default, translate rather than relay, and never hand the browser more than
 * it needs.
 */
final class HandlersTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private const EVT = '{"id":"evt_1","event":"verification.flagged","data":{"phone":"+212600000000"}}';

    /** @var list<string> */
    private array $logged = [];

    private function handlers(array $responses, ?callable $authorize, ?string $secret = null): array
    {
        $this->logged = [];
        $t = new FakeTransport($responses);
        $numra = new Numra([
            'apiKey' => 'k',
            'baseUrl' => 'https://api.example.test',
            'transport' => $t,
            'sleeper' => static function (int $ms): void {
            },
        ]);

        $h = new Handlers(
            $numra,
            $authorize,
            $secret,
            log: function (string $m): void {
                $this->logged[] = $m;
            },
        );

        return [$h, $t];
    }

    public function testWithNoAuthorizeEveryRequestIsRefusedAsAMisconfiguration(): void
    {
        [$h, $t] = $this->handlers([], null);
        $out = $h->check(['phone' => '0600000000']);

        /* 500, not 403: nobody asked for this to be locked, it just is. A 403
           reads as "this user lacks permission" and sends the integrator
           hunting through their session code. */
        self::assertSame(500, $out['status']);
        self::assertSame('NUMRA_NOT_CONFIGURED', $out['body']['error']);
        self::assertCount(0, $t->calls, 'no quota spent');
        /* And the message has to say exactly what to write, or it gets
           "fixed" with fn () => true. */
        self::assertStringContainsString('authorize', implode("\n", $this->logged));
    }

    public function testARejectingAuthorizeIsAPlain403(): void
    {
        [$h, $t] = $this->handlers([], static fn (): bool => false);
        $out = $h->check(['phone' => '0600000000']);

        self::assertSame(403, $out['status']);
        self::assertSame('FORBIDDEN', $out['body']['error']);
        self::assertCount(0, $t->calls);
    }

    public function testAnAuthorizeThatThrowsFailsClosed(): void
    {
        /* A session lookup hitting a dead database must not become an open
           door. */
        [$h, $t] = $this->handlers([], static function (): bool {
            throw new \RuntimeException('db down');
        });

        self::assertSame(403, $h->check(['phone' => '0600000000'])['status']);
        self::assertCount(0, $t->calls);
    }

    public function testAnAuthorizeReturningTruthyButNotTrueStillDenies(): void
    {
        /* PHP is loose about truthiness; this is not the place to be. An
           authorize that accidentally returns the string "no" must not open
           the endpoint. */
        [$h, $t] = $this->handlers([], static fn (): string => 'no');

        self::assertSame(403, $h->check(['phone' => '0600000000'])['status']);
        self::assertCount(0, $t->calls);
    }

    public function testCheckReturnsTheNarrowedPayloadNotTheEngineInternals(): void
    {
        [$h] = $this->handlers([['body' => Fixtures::LOOKUP_OK]], static fn (): bool => true);
        $out = $h->check(['phone' => '0600000000']);

        self::assertSame(200, $out['status']);
        self::assertSame('HIGH', $out['body']['riskLevel']);
        self::assertSame('reactive', $out['body']['customerStyle']['code']);
        /* `raw` would leak the shape of our ledger and `risk_score_raw` is
           engine diagnostics. Neither is the browser's business. */
        self::assertArrayNotHasKey('raw', $out['body']);
        self::assertArrayNotHasKey('risk_score_raw', $out['body']);
        /* Identical key set to @getnumra/core's forBrowser, so @getnumra/react
           renders a PHP backend with no adapter in between. */
        self::assertSame(
            ['phone', 'verdict', 'riskLevel', 'riskScore', 'trustScore', 'confidence', 'isRated', 'isBlacklisted', 'customerStyle'],
            array_keys($out['body']),
        );
    }

    public function testAMissingPhoneIsA400BeforeAnythingIsSpent(): void
    {
        [$h, $t] = $this->handlers([], static fn (): bool => true);
        $out = $h->check([]);

        self::assertSame(400, $out['status']);
        self::assertSame('INVALID_PAYLOAD', $out['body']['error']);
        self::assertCount(0, $t->calls);
    }

    public function testABadCredentialIsNeverRelayedToTheBrowserAs401(): void
    {
        /* The merchant's credential problem is not the visitor's business,
           and a 401 in a browser reads as "you are logged out". */
        [$h] = $this->handlers([[
            'status' => 401,
            'body' => ['ok' => false, 'error' => 'LICENSE_EXPIRED', 'message' => 'expired'],
        ]], static fn (): bool => true);

        $out = $h->check(['phone' => '0600000000']);
        self::assertSame(502, $out['status']);
        self::assertSame('UPSTREAM_UNAVAILABLE', $out['body']['error']);
        self::assertStringContainsString('LICENSE_EXPIRED', implode("\n", $this->logged));
    }

    public function testAnExhaustedQuotaIsA503(): void
    {
        [$h] = $this->handlers([[
            'status' => 429,
            'body' => ['ok' => false, 'error' => 'QUOTA_EXCEEDED'],
        ]], static fn (): bool => true);

        self::assertSame(503, $h->check(['phone' => '0600000000'])['status']);
    }

    /** @return array<string, string> */
    private static function sign(string $body, ?int $ts = null): array
    {
        $ts ??= time();

        return [
            'Numra-Signature' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, self::SECRET),
            'Numra-Timestamp' => (string) $ts,
        ];
    }

    public function testASignedWebhookVerifiesAndHandsBackTheEvent(): void
    {
        [$h] = $this->handlers([], static fn (): bool => true, self::SECRET);
        $out = $h->webhook(self::EVT, self::sign(self::EVT));

        self::assertSame(200, $out['status']);
        self::assertSame('verification.flagged', $out['event']['event']);
    }

    public function testAForgedSignatureIs400AndCarriesNoEvent(): void
    {
        [$h] = $this->handlers([], static fn (): bool => true, self::SECRET);
        $out = $h->webhook(self::EVT, [
            'Numra-Signature' => 'sha256=deadbeef',
            'Numra-Timestamp' => (string) time(),
        ]);

        /* 400 not 401: an unauthentic sender has no credential to fix, and
           401 invites a retry storm. */
        self::assertSame(400, $out['status']);
        self::assertArrayNotHasKey('event', $out);
    }

    public function testAConsumedBodyAccusesTheConfigurationNotTheSender(): void
    {
        /* The failure that used to arrive as "invalid signature" — which reads
           as "Numra sent a bad webhook" and ends with someone disabling
           verification. In PHP the usual cause is an empty php://input,
           because something read the stream first or the request came in as
           form data. */
        [$h] = $this->handlers([], static fn (): bool => true, self::SECRET);

        /* Two shapes mean the bytes are genuinely gone: an already-parsed
           array, and an empty body that arrived with a form Content-Type,
           which is PHP consuming the stream into $_POST before our code runs. */
        $out = $h->webhook(['already' => 'parsed'], self::sign(self::EVT));
        self::assertSame(500, $out['status']);
        self::assertSame('NUMRA_RAW_BODY_UNAVAILABLE', $out['body']['error']);

        $formHeaders = self::sign(self::EVT) + ['Content-Type' => 'application/x-www-form-urlencoded'];
        $out = $h->webhook('', $formHeaders);
        self::assertSame(500, $out['status']);
        self::assertSame('NUMRA_RAW_BODY_UNAVAILABLE', $out['body']['error']);
    }

    public function testAnEmptyBodyOnItsOwnIsUnauthenticNotAMisconfiguration(): void
    {
        /* Anyone can send Content-Length: 0. Answering with a 500 and an alarm
           accusing the merchant's own setup is how someone gets talked into
           disabling verification, which is what this whole path is for. */
        [$h] = $this->handlers([], static fn (): bool => true, self::SECRET);

        $out = $h->webhook('', ['Content-Type' => 'application/json']);
        self::assertSame(400, $out['status']);
        self::assertSame('missing_signature', $out['body']['error']);
        self::assertCount(0, $this->logged, 'an unauthentic request must not raise an alarm');
    }

    public function testWithoutASecretTheWebhookRouteDoesNotExist(): void
    {
        [$h] = $this->handlers([], static fn (): bool => true);
        self::assertSame(404, $h->webhook(self::EVT, self::sign(self::EVT))['status']);
    }
}
