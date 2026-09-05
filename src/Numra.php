<?php

declare(strict_types=1);

namespace Numra;

/**
 * The Numra PHP client.
 *
 * SERVER-SIDE ONLY. This is a shared fraud ledger, so the key it carries is
 * worth stealing; it must never be echoed into a page, a JS bundle, or a
 * template variable.
 *
 * Ported from @numra/core, deliberately decision-for-decision: same error
 * codes, same retry policy, same idempotency rules, same webhook scheme. Two
 * clients that disagree about what a 429 means are two clients that behave
 * differently on the worst day of the month.
 *
 * Requires only ext-curl and ext-json — a fraud client that drags a tree of
 * transitive packages into a merchant's checkout is a supply-chain surface
 * nobody asked for.
 */
final class Numra
{
    public const VERSION = '1.0.0';

    /* An upper bound on how long a server may park a checkout. See retry()
       for why an unclamped Retry-After was the most dangerous line in this
       file. */
    private const MAX_BACKOFF_MS = 20000;

    /** QUOTA_EXCEEDED is separated from RATE_LIMITED deliberately: one clears
        in a minute and the other at midnight, and a caller's backoff should
        not treat them alike. */
    private const KNOWN_API_CODES = [
        'LICENSE_MISSING', 'LICENSE_INVALID', 'LICENSE_EXPIRED', 'LICENSE_BOUND',
        'COUNTRY_NOT_ALLOWED', 'INVALID_PAYLOAD', 'ENDPOINT_NOT_FOUND',
        'RATE_LIMITED', 'QUOTA_EXCEEDED',
    ];

    private string $apiKey;
    private string $baseUrl;
    private float $timeout;
    private int $maxRetries;
    private Transport $transport;
    private string $userAgent;
    /** @var null|callable(int):void  Overridable so tests do not sleep. */
    private $sleeper;

    /**
     * @param array{
     *   apiKey: string,
     *   baseUrl?: string,
     *   timeout?: float,
     *   maxRetries?: int,
     *   integration?: string,
     *   transport?: Transport,
     *   sleeper?: callable(int):void
     * } $options
     */
    public function __construct(array $options)
    {
        $key = $options['apiKey'] ?? '';
        if (!\is_string($key) || $key === '') {
            /* Fails at construction, not at the first call. A missing key
               discovered during a checkout is a missing key discovered by a
               customer. */
            throw new NumraError('LICENSE_MISSING', 'A Numra API key is required: new Numra([\'apiKey\' => ...]).');
        }

        $this->apiKey = $key;
        $this->baseUrl = rtrim((string) ($options['baseUrl'] ?? 'https://api.numra.ma'), '/');
        $this->timeout = (float) ($options['timeout'] ?? 10.0);
        $this->maxRetries = (int) ($options['maxRetries'] ?? 2);
        $this->transport = $options['transport'] ?? new CurlTransport();
        $this->sleeper = $options['sleeper'] ?? null;

        /* Sent on every request so we can report which SDK versions are actually
           live in the field, rather than which ones we published. Nothing
           identifying goes in it — package, version, integration and runtime. */
        $integration = isset($options['integration']) ? ' ' . $options['integration'] : '';
        $this->userAgent = 'numra-php/' . self::VERSION . $integration . ' php/' . PHP_VERSION;
    }

    private static function classify(int $status, ?string $code): string
    {
        if ($code !== null && \in_array($code, self::KNOWN_API_CODES, true)) {
            return $code;
        }
        if ($status === 429) {
            return 'RATE_LIMITED';
        }
        if ($status >= 500) {
            return 'SERVER_ERROR';
        }
        if ($status === 401 || $status === 403) {
            return 'LICENSE_INVALID';
        }
        if ($status === 404) {
            return 'ENDPOINT_NOT_FOUND';
        }
        /* Anything else in the 3xx/4xx range is OUR fault, and the difference
           matters because SERVER_ERROR is retryable: a typo'd baseUrl used to
           spend the whole retry budget on every checkout re-asking a question
           that could never succeed, then report Numra as down. */
        if ($status >= 300 && $status < 500) {
            return 'INVALID_PAYLOAD';
        }

        return 'SERVER_ERROR';
    }

    /** @param array<string, mixed> $body */
    private function request(string $path, array $body, int $attempt = 0): array
    {
        /* Unset optionals are dropped, not sent as null — including inside
           `context`. This is parity, not taste: JSON.stringify drops
           `undefined`, so @numra/core has never sent those keys, and a PHP
           client that starts sending explicit nulls is a second client with a
           different wire shape. The whole point of porting decision-for-
           decision is that the API sees one request either way. */
        $payload = json_encode(
            self::dropNulls($body),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        try {
            $res = $this->transport->post(
                $this->baseUrl . $path,
                $payload,
                [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    /* Required by the API. Morocco is the only market served,
                       and the API refuses rather than returning empty results
                       for anywhere else. */
                    'X-Country' => 'MA',
                    'User-Agent' => $this->userAgent,
                ],
                $this->timeout,
            );
        } catch (NumraError $e) {
            if ($e->isRetryable() && $attempt < $this->maxRetries) {
                return $this->retry($path, $body, $attempt, $e);
            }
            throw $e;
        }

        $json = json_decode($res['body'], true);
        if (!\is_array($json)) {
            /* A body we cannot parse is a server problem, not a caller
               problem. */
            $json = null;
        }
        $requestId = $res['headers']['x-request-id'] ?? null;

        $ok = $res['status'] >= 200 && $res['status'] < 300;

        /* THE ONE THAT MATTERED MOST.
           ─────────────────────────────────────────────────────────────────
           This used to be `return $json ?? []`. On a 2xx whose body would not
           parse — a captive portal's HTML interstitial, a corporate proxy's
           login page, a 204, a truncated response — that handed back an empty
           array, and PhoneCheck::fromArray([]) defaults every field: verdict
           UNRATED, risk_score 0, is_blacklisted false.

           So a blacklisted number came back CLEAN. No error, no log line,
           nothing the merchant could detect, and toBrowserArray() shipped the
           fabricated verdict to the storefront. A fraud product that answers
           "fine" when it did not get an answer is worse than one that is
           down, because being down is visible.

           NumraError already documents SERVER_ERROR as "a 5xx, or a body we
           could not parse". The doc comment was right and the code did the
           opposite. */
        if ($ok && !\is_array($json)) {
            $err = new NumraError(
                'SERVER_ERROR',
                'Numra answered ' . $res['status'] . ' but the body was not JSON. Treating it as a failed lookup rather than an empty result.',
                $res['status'],
                $requestId,
            );
            if ($attempt < $this->maxRetries) {
                return $this->retry($path, $body, $attempt, $err);
            }

            throw $err;
        }

        if ($ok && ($json['ok'] ?? true) !== false) {
            return $json;
        }

        $retryAfter = isset($res['headers']['retry-after']) && is_numeric($res['headers']['retry-after'])
            ? (int) $res['headers']['retry-after']
            : null;

        $err = new NumraError(
            self::classify($res['status'], \is_string($json['error'] ?? null) ? $json['error'] : null),
            (string) ($json['message'] ?? sprintf('Numra returned %d.', $res['status'])),
            $res['status'],
            \is_string($requestId) ? $requestId : null,
            $retryAfter,
            /* Guarded like `error` on the line above. Under strict_types an
               array here raised a TypeError out of the constructor, and the
               500 that caused it was lost. */
            \is_string($json['docs_url'] ?? null) ? $json['docs_url'] : null,
            $json,
        );

        /* QUOTA_EXCEEDED is NOT retried even though it arrives as a 429. The
           quota resets at midnight; retrying inside the request turns one
           exhausted day into sustained hammering and never gets an answer. */
        if ($err->isRetryable() && !$err->isQuotaError() && $attempt < $this->maxRetries) {
            return $this->retry($path, $body, $attempt, $err);
        }

        throw $err;
    }

    /** @param array<string, mixed> $body */
    private function retry(string $path, array $body, int $attempt, NumraError $err): array
    {
        /* Exponential backoff with full jitter. Without jitter every store
           that hit the same blip retries in lockstep and re-creates it.
           Retry-After wins when the server sent one — it knows more than we
           do — but only up to MAX_BACKOFF_MS.

           Unclamped, this was the worst bug in the PHP client. `Retry-After:
           86400` is ordinary output from a rate limiter under load, and it
           became a 24-hour blocking usleep inside an FPM worker.
           `max_execution_time` does not count time asleep, so the worker was
           simply gone; a few hundred orders exhausted the pool and took the
           whole store down rather than just the fraud check.

           Jitter now applies to the Retry-After branch too. It was the branch
           that needed it most and the one that did not have it: every client
           rate-limited in the same window got the identical value and would
           have woken in lockstep. */
        /* Two different kinds of wait, and they must be jittered differently.

           When the server sent Retry-After it gave an instruction, and jitter
           may only ADD to it — full jitter multiplies by 0.5–1.5, so it would
           have us come back BEFORE the server said to, which is the one thing
           that header exists to prevent. A second of spread is enough to
           decorrelate a fleet that all received the identical value.

           Our own curve has no instruction to respect, so it takes full
           jitter. max(0, ...) on the header because a negative Retry-After
           used to reach usleep() and throw a raw ValueError straight out of
           the SDK, past every documented `catch (NumraError)`. */
        $ms = $err->retryAfter !== null
            ? (int) round(max(0, $err->retryAfter) * 1000 + random_int(0, 1000))
            : (int) round((2 ** $attempt) * 250 * (0.5 + (random_int(0, 1000) / 1000)));
        $ms = min($ms, self::MAX_BACKOFF_MS);

        if ($this->sleeper !== null) {
            ($this->sleeper)($ms);
        } else {
            usleep($ms * 1000);
        }

        return $this->request($path, $body, $attempt + 1);
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private static function dropNulls(array $a): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            if ($v === null) {
                continue;
            }
            $out[$k] = \is_array($v) ? self::dropNulls($v) : $v;
        }

        return $out;
    }

    /**
     * Check a phone number before you ship.
     *
     *   $check = $numra->check('0600000000');
     *   if ($check->isBlacklisted || $check->riskLevel === 'CRITICAL') { hold($order); }
     *
     * @param string $phone   any Moroccan spelling; the API normalises it
     * @param array{
     *   eventType?: string,
     *   includeTimeline?: bool,
     *   context?: array{paymentMethod?: string, orderTotal?: float, currency?: string, region?: string, note?: string}
     * } $options
     *
     * @throws NumraError
     */
    public function check(string $phone, array $options = []): PhoneCheck
    {
        if (trim($phone) === '') {
            throw new NumraError('INVALID_PAYLOAD', 'check($phone) requires a phone number.');
        }

        $c = $options['context'] ?? null;

        return PhoneCheck::fromArray($this->request('/v1/phone/lookup', [
            'phone' => $phone,
            'event_type' => $options['eventType'] ?? null,
            'include_timeline' => $options['includeTimeline'] ?? null,
            'context' => $c === null ? null : [
                'payment_method' => $c['paymentMethod'] ?? null,
                'order_total' => $c['orderTotal'] ?? null,
                'currency' => $c['currency'] ?? null,
                'region' => $c['region'] ?? null,
                'note' => $c['note'] ?? null,
            ],
        ]));
    }

    /**
     * Report what happened to an order. This is the half that gets skipped,
     * and the half that makes the ledger worth reading — a merchant who only
     * calls check() is querying a database they never write to.
     *
     * Idempotent on (merchant, orderId, outcomeType): calling it twice is safe
     * and returns recorded=false, idempotent=true the second time.
     *
     * @param array{
     *   phone: string, orderId: string, outcomeType: string,
     *   orderTotal?: float, currency?: string, region?: string, note?: string
     * } $input
     *
     * @throws NumraError
     */
    public function reportOutcome(array $input): OutcomeResult
    {
        foreach (['phone' => 'a phone', 'orderId' => 'an orderId — it is half the idempotency key', 'outcomeType' => 'an outcomeType'] as $k => $what) {
            if (empty($input[$k])) {
                throw new NumraError('INVALID_PAYLOAD', "reportOutcome requires $what.");
            }
        }

        return OutcomeResult::fromArray($this->request('/v1/phone/outcome', [
            'phone' => $input['phone'],
            'order_id' => $input['orderId'],
            'outcome_type' => $input['outcomeType'],
            'order_total' => $input['orderTotal'] ?? null,
            'currency' => $input['currency'] ?? null,
            'region' => $input['region'] ?? null,
            'note' => $input['note'] ?? null,
        ]));
    }

    /**
     * Credential status and remaining quota — for a settings screen or a
     * start-up check. Not required before check(), which authorises itself;
     * calling it first only doubles the round trips.
     *
     * @throws NumraError
     */
    public function verifyLicense(): LicenseStatus
    {
        return LicenseStatus::fromArray($this->request('/v1/license/verify', []));
    }
}
