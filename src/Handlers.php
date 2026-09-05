<?php

declare(strict_types=1);

namespace Numra;

/**
 * Framework-neutral request handling.
 *
 * The PHP twin of @getnumra/core's createHandlers. Laravel, Symfony, Slim and a
 * plain front controller all do the same four things — authorise, call Numra,
 * narrow the result for the browser, translate upstream failures — and written
 * four times they drift. The one that drifts silently is *deny by default*,
 * which is the difference between a private endpoint and an open relay
 * pointed at the merchant's paid quota.
 *
 * So it lives here, once. Every method returns ['status' => int, 'body' =>
 * array] and none of them throw. Nothing in this file knows what a Response
 * is.
 */
final class Handlers
{
    public const NOT_CONFIGURED_DEFAULT_USAGE =
        'new Handlers($numra, authorize: fn ($ctx) => (bool) auth()->check())';

    /* Kept in step with MAX_PHONE_LENGTH in @getnumra/core's client.js. A
       Moroccan number is ten digits; this is generous for any spelling and
       small enough that nothing can be smuggled through the field. */
    public const MAX_PHONE_LENGTH = 32;

    /** @var null|callable(mixed):bool */
    private $authorize;
    /** @var callable(string):void */
    private $log;
    /** Latches the not-configured diagnostic to once per process. */
    private bool $saidNotConfigured = false;

    public function __construct(
        private readonly Numra $client,
        /**
         * Runs before every lookup. Return false to reject.
         *
         * REQUIRED in practice. Leave it null and every request is refused
         * with a message saying so — this route spends the merchant's quota,
         * and every lookup is billable.
         */
        ?callable $authorize = null,
        private readonly ?string $webhookSecret = null,
        /** The paste-able fix printed when $authorize is missing. */
        private readonly string $usage = self::NOT_CONFIGURED_DEFAULT_USAGE,
        ?callable $log = null,
    ) {
        $this->authorize = $authorize;
        $this->log = $log ?? static function (string $m): void {
            error_log($m);
        };
    }

    public function notConfiguredMessage(): string
    {
        return "[numra] Refusing every request because no \$authorize was provided.\n"
            . "        This route spends your Numra quota, so it must not be open.\n"
            . '        ' . $this->usage;
    }

    /** @return null|array{status: int, body: array<string, mixed>} null when allowed */
    private function guard(mixed $ctx): ?array
    {
        if ($this->authorize === null) {
            /* A configuration mistake, not a permissions one — and 500 rather
               than 403 for that reason. A 403 reads as "this user lacks
               permission" and sends the integrator hunting through their
               session code; the message has to say exactly what to write, or
               it gets "fixed" with fn () => true. */
            /* Said once per process, not once per request. This endpoint is
               public, so a scanner hitting a mid-deploy misconfiguration used
               to write a three-line diagnostic to the merchant's disk on every
               hit. A configuration error is worth saying loudly and worth
               saying once. */
            if (!$this->saidNotConfigured) {
                $this->saidNotConfigured = true;
                ($this->log)($this->notConfiguredMessage());
            }

            return ['status' => 500, 'body' => [
                'error' => 'NUMRA_NOT_CONFIGURED',
                'message' => 'This endpoint has no authorize function.',
            ]];
        }

        try {
            $allowed = ($this->authorize)($ctx) === true;
        } catch (\Throwable) {
            /* Fail closed. A session lookup that throws must not become an
               open door — that is how a database blip turns into a spending
               spree. */
            $allowed = false;
        }

        return $allowed ? null : ['status' => 403, 'body' => ['error' => 'FORBIDDEN']];
    }

    /**
     * Upstream failures are translated, never relayed.
     *
     * A rejected credential is the MERCHANT's problem, not the visitor's, and
     * a 401 arriving in a browser reads as "you are logged out". It becomes a
     * 502 and the detail goes to the server log.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function translateError(\Throwable $e): array
    {
        if ($e instanceof NumraError) {
            if ($e->isAuthError()) {
                ($this->log)('[numra] credential rejected: ' . $e->errorCode . ' ' . $e->getMessage());

                return ['status' => 502, 'body' => ['error' => 'UPSTREAM_UNAVAILABLE']];
            }
            if ($e->isQuotaError()) {
                return ['status' => 503, 'body' => ['error' => 'QUOTA_EXCEEDED']];
            }
            if ($e->errorCode === 'INVALID_PAYLOAD') {
                return ['status' => 400, 'body' => ['error' => 'INVALID_PAYLOAD', 'message' => $e->getMessage()]];
            }

            return ['status' => 502, 'body' => ['error' => 'UPSTREAM_UNAVAILABLE']];
        }

        ($this->log)('[numra] unexpected: ' . $e->getMessage());

        return ['status' => 500, 'body' => ['error' => 'INTERNAL']];
    }

    /**
     * @param  array<string, mixed> $input
     * @return array{status: int, body: array<string, mixed>}
     */
    public function check(array $input, mixed $ctx = null): array
    {
        if ($refusal = $this->guard($ctx)) {
            return $refusal;
        }

        $phone = $input['phone'] ?? null;
        if (!\is_string($phone) || trim($phone) === '') {
            return ['status' => 400, 'body' => ['error' => 'INVALID_PAYLOAD', 'message' => 'phone is required']];
        }
        /* A cap at the public edge. Nothing bounded this before, and the JS
           adapters each bounded it differently or not at all, so an
           authorised session could push megabytes through as a "phone
           number" — one billable lookup and the merchant's egress per
           request. A Moroccan number is ten digits. */
        if (\strlen($phone) > self::MAX_PHONE_LENGTH) {
            return ['status' => 400, 'body' => [
                'error' => 'INVALID_PAYLOAD',
                'message' => 'phone is longer than ' . self::MAX_PHONE_LENGTH . ' characters',
            ]];
        }

        try {
            return ['status' => 200, 'body' => $this->client->check($phone)->toBrowserArray()];
        } catch (\Throwable $e) {
            return $this->translateError($e);
        }
    }

    /**
     * @param  array<string, mixed> $input
     * @return array{status: int, body: array<string, mixed>}
     */
    public function outcome(array $input, mixed $ctx = null): array
    {
        if ($refusal = $this->guard($ctx)) {
            return $refusal;
        }

        /* These used to be blind (string) casts, so `phone: ["a","b"]` raised
           an "Array to string conversion" warning and wrote the literal word
           "Array" into the merchant's ledger, while `currency` and `note`
           passed arbitrary nested objects straight through to the wire.
           `orderId` is half the idempotency key, so a non-string there
           poisons idempotency for that merchant. */
        foreach ([
            ['phone', self::MAX_PHONE_LENGTH, true],
            ['orderId', 200, true],
            ['outcomeType', 64, true],
            ['currency', 8, false],
            ['region', 120, false],
            ['note', 500, false],
        ] as [$field, $max, $required]) {
            $v = $input[$field] ?? null;
            if ($v === null && !$required) {
                continue;
            }
            if (!\is_string($v) || trim($v) === '') {
                return ['status' => 400, 'body' => [
                    'error' => 'INVALID_PAYLOAD',
                    'message' => $field . ' must be a non-empty string',
                ]];
            }
            if (\strlen($v) > $max) {
                return ['status' => 400, 'body' => [
                    'error' => 'INVALID_PAYLOAD',
                    'message' => $field . ' is longer than ' . $max . ' characters',
                ]];
            }
        }
        if (isset($input['orderTotal']) && !\is_numeric($input['orderTotal'])) {
            return ['status' => 400, 'body' => [
                'error' => 'INVALID_PAYLOAD',
                'message' => 'orderTotal must be a number',
            ]];
        }

        try {
            $r = $this->client->reportOutcome([
                'phone' => (string) $input['phone'],
                'orderId' => (string) $input['orderId'],
                'outcomeType' => (string) $input['outcomeType'],
                'orderTotal' => isset($input['orderTotal']) ? (float) $input['orderTotal'] : null,
                'currency' => $input['currency'] ?? null,
                'region' => $input['region'] ?? null,
                'note' => $input['note'] ?? null,
            ]);

            return ['status' => 200, 'body' => [
                'recorded' => $r->recorded,
                'idempotent' => $r->idempotent,
            ]];
        } catch (\Throwable $e) {
            return $this->translateError($e);
        }
    }

    /**
     * Verify a webhook.
     *
     * $rawBody must be the exact bytes — `file_get_contents('php://input')`.
     * The caller acknowledges BEFORE running the merchant's handler: Numra
     * retries on a non-2xx, so a slow handler would otherwise become
     * duplicate deliveries.
     *
     * @param  array<string, string|string[]> $headers
     * @return array{status: int, body: array<string, mixed>, event?: array<string, mixed>}
     */
    /**
     * Did PHP itself eat the body?
     *
     * A form-encoded or multipart POST is parsed into $_POST before any of our
     * code runs, leaving php://input empty. That is a real misconfiguration
     * worth a loud 500 — Numra sends JSON, so a form Content-Type means
     * something rewrote the request on the way in.
     *
     * @param array<string, mixed> $headers
     */
    private static function looksConsumedByPhp(array $headers): bool
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) !== 'content-type') {
                continue;
            }
            $v = strtolower((string) (\is_array($value) ? ($value[0] ?? '') : $value));

            return str_contains($v, 'application/x-www-form-urlencoded')
                || str_contains($v, 'multipart/form-data');
        }

        return false;
    }

    public function webhook(mixed $rawBody, array $headers): array
    {
        if ($this->webhookSecret === null || $this->webhookSecret === '') {
            return ['status' => 404, 'body' => ['error' => 'NOT_FOUND']];
        }

        /* An empty body has two completely different causes, and they used to
           be folded together as "your configuration is broken".

           The original reasoning was sound for one of them: in PHP a
           form-encoded POST is consumed into $_POST and php://input reads
           empty, so the bytes really are gone and the merchant really does
           need telling. But it does not hold for the other: anyone on the
           internet can send Content-Length: 0, and answering that with a 500
           plus an alarm accusing the merchant's own setup is a way to talk
           someone into disabling webhook verification — the outcome this file
           exists to prevent.

           The Content-Type tells them apart, and it is already in $headers. A
           form-encoded or multipart request with an empty body is the
           consumed-stream case. Anything else is simply unauthentic. */
        $isEmpty = \is_string($rawBody) && $rawBody === '';

        if ($isEmpty && !self::looksConsumedByPhp($headers)) {
            return ['status' => 400, 'body' => [
                'error' => 'missing_signature',
                'message' => 'Empty request body.',
            ]];
        }

        /* Either a non-string — an already-parsed array — or an empty body
           that arrived with a form Content-Type, which in PHP means the stream
           was consumed into $_POST before any of our code ran. Both mean the
           bytes are genuinely gone. */
        if (!\is_string($rawBody) || $isEmpty) {
            /* NOT a 400. "Invalid signature" reads as "Numra sent a bad
               webhook" and ends with someone disabling verification; this
               accuses the configuration, which is what is actually wrong.
               The two causes that reach here: an already-parsed body, or a
               form-encoded request, which PHP consumes into $_POST leaving
               php://input empty. An empty body on its own is NOT one of them —
               anyone can send Content-Length: 0, and answering that with an
               alarm about the merchant's own setup is how someone gets talked
               into turning verification off. That case is a 400 above. */
            ($this->log)(
                "[numra] Cannot verify this webhook: the raw body is not available.\n"
                . "        Pass file_get_contents('php://input'), not \$_POST and not a re-encoded array.\n"
                . '        An empty body usually means something read the stream first.',
            );

            return ['status' => 500, 'body' => [
                'error' => 'NUMRA_RAW_BODY_UNAVAILABLE',
                'message' => 'The raw request body was not available for signature verification. See the server log.',
            ]];
        }

        try {
            $event = Webhooks::verify($rawBody, $headers, $this->webhookSecret);

            return ['status' => 200, 'body' => ['ok' => true], 'event' => $event];
        } catch (WebhookVerificationError $e) {
            /* 400, not 401: an unauthentic sender has no credential to fix,
               and 401 invites a retry storm. */
            return ['status' => 400, 'body' => ['error' => $e->reason, 'message' => $e->getMessage()]];
        } catch (\Throwable $e) {
            return $this->translateError($e);
        }
    }
}
