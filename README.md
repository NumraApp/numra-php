# numra/numra-php

**Cash-on-delivery phone checks, outcome reporting and webhook verification for PHP applications.**

[![Packagist version](https://img.shields.io/packagist/v/numra/numra-php)](https://packagist.org/packages/numra/numra-php) [![Packagist downloads](https://img.shields.io/packagist/dt/numra/numra-php)](https://packagist.org/packages/numra/numra-php) [![licence: MIT](https://img.shields.io/packagist/l/numra/numra-php)](LICENSE)

The Numra client for PHP. Cash-on-delivery risk checks, outcome reporting and
webhook verification.

```bash
composer require numra/numra-php
```

Needs PHP 8.1+, `ext-curl` and `ext-json`. Nothing else — a fraud client that
drags a tree of transitive packages into a merchant's checkout is a
supply-chain surface nobody asked for.

## Check before you ship

```php
use Numra\Numra;

$numra = new Numra(['apiKey' => getenv('NUMRA_API_KEY')]);

$check = $numra->check('0600000000');

if ($check->isBlacklisted || $check->riskLevel === 'CRITICAL') {
    hold($order);
}
```

Then report what happened. This is the half that gets skipped, and the half
that makes the ledger worth reading — a merchant who only calls `check()` is
querying a database they never write to.

```php
$numra->reportOutcome([
    'phone'       => '0600000000',
    'orderId'     => $order->id,
    'outcomeType' => 'REFUSED_COD',
]);
```

Idempotent on `(merchant, orderId, outcomeType)`. Calling it twice is safe and
returns `recorded: false, idempotent: true` the second time.

## Reading the result

`riskScore` alone **cannot** tell a checked-and-clean customer from a complete
stranger — both come back low. On a cash-on-delivery store most buyers are new,
so this is the distinction that matters:

| Field | What it tells you |
|---|---|
| `isRated` | whether there is any history at all |
| `confidence` | how much history is behind the score |
| `trustScore` | the two above already folded into one number |
| `riskLevel` | `UNRATED`, `LOW`, `MEDIUM`, `HIGH`, `CRITICAL` |
| `customerStyle` | a behavioural bucket, not a verdict |
| `raw` | the untouched response, so a new server-side field needs no SDK release |

## Errors

Catch `Numra\NumraError` and switch on `$e->errorCode`, never on the message —
the code is the stable surface, the message is written for humans and changes.

```php
try {
    $check = $numra->check($phone);
} catch (\Numra\NumraError $e) {
    if ($e->isAuthError())  { /* your key — retrying will never help */ }
    if ($e->isQuotaError()) { /* out until midnight */ }
    if ($e->isRetryable())  { /* transient */ }
}
```

`NETWORK_ERROR` means nobody answered; every other code means Numra said no.
A merchant deciding whether to ship the parcel anyway has to be able to tell
those apart, so they are never flattened together.

### When Numra is unreachable

**This client neither fails open nor fails closed. It throws, and you decide.**

There is no default here on purpose: a fraud signal that quietly returns "looks
fine" during an outage is worse than no signal, and one that blocks checkout
turns our downtime into your lost orders. Only you know which of those your
business can absorb.

So decide it in code now rather than at 3am. The branches above are that
decision, and `NETWORK_ERROR` needs its own — most cash-on-delivery merchants
take the order and re-check it before dispatch, which keeps the till open
without shipping blind.

Retries are built in: two by default, exponential backoff with full jitter,
`Retry-After` obeyed when the server sends one. `QUOTA_EXCEEDED` is never
retried even though it arrives as a 429 — the quota resets at midnight, and
retrying inside the request turns one exhausted day into sustained hammering.

## Webhooks

Verify against the **raw** bytes. `json_encode(json_decode($x))` is not `$x` —
key order, whitespace and unicode escaping all move — so a re-serialised body
can never match.

```php
use Numra\Webhooks;
use Numra\WebhookVerificationError;

$raw = file_get_contents('php://input');   // not $_POST

try {
    $event = Webhooks::verify($raw, getallheaders(), getenv('NUMRA_WEBHOOK_SECRET'));
} catch (WebhookVerificationError $e) {
    http_response_code(400);
    exit;
}

http_response_code(200);   // acknowledge FIRST
flush();
handle($event);            // then do the slow part
```

Acknowledge before handling: Numra retries on a non-2xx, so a slow handler
turns into duplicate deliveries.

The header lookup is genuinely case-insensitive and understands the `$_SERVER`
spelling (`HTTP_NUMRA_SIGNATURE`) as well as PSR-7 array values, because PHP
hands you a different case depending on where you read from.

## Serving `@getnumra/react` from PHP

`Numra\Handlers` is the framework-neutral endpoint — the PHP twin of
`createHandlers` in `@getnumra/core`. It returns `['status' => int, 'body' =>
array]` and never throws, so a controller is three lines.

```php
$handlers = new Numra\Handlers(
    $numra,
    authorize: fn ($ctx) => (bool) auth()->check(),   // required
    webhookSecret: getenv('NUMRA_WEBHOOK_SECRET'),
);

$out = $handlers->check(['phone' => $request->input('phone')], $request);
```

`authorize` is not optional in practice. Leave it out and **every request is
refused** with a 500 that says so — this route spends your quota, and every
lookup is billable, so an open one is a relay pointed at your own bill. An
`authorize` that throws denies: a database blip must not become an open door.

Rate-limit the route as well. `authorize` decides who may spend your quota,
not how much, and on a public checkout the guard is a session that owns a cart
— which any visitor gets by loading the page. `Handlers` deliberately holds no
counter of its own, because the only place that can count across your PHP-FPM
workers is the store you already run; a few lines against Redis, Memcached or
a table, keyed per IP or per session, in front of `check()` and `outcome()`.
Leave `webhook()` out of it: Numra retries a non-2xx, so a 429 there comes
straight back as a redelivery.

Keep the key in the environment and out of version control. A key committed
once is in the history of every clone of that repository, and rotating it is
the only fix.

The response `check()` hands back has exactly the keys `@getnumra/react` expects,
so the React components render a PHP backend with no adapter in between.

## Release notes

Every release is tagged and written up on the
[Releases page](https://github.com/NumraApp/numra-php/releases). The same
history in one file is in [CHANGELOG.md](CHANGELOG.md).

## Contributing

Bug reports and patches are welcome. [CONTRIBUTING.md](CONTRIBUTING.md) covers
running the tests, the regression test a change is expected to bring with it,
and which repository a given fix actually belongs in.

## Security

Vulnerabilities go privately to the address in [SECURITY.md](SECURITY.md).
**Do not open a public issue for a security problem** — this client holds a
credential that reads a shared fraud ledger, and a public report is a working
exploit for every merchant using it until a fix ships.

## The rest of the family

Twelve packages, one contract. The server side holds the API key; the browser
side calls the endpoint the server side mounts.

Server:

| Package | Repository |
|---|---|
| `@getnumra/core` | [numra-js-core](https://github.com/NumraApp/numra-js-core) |
| `@getnumra/express` | [numra-express](https://github.com/NumraApp/numra-express) |
| `@getnumra/fastify` | [numra-fastify](https://github.com/NumraApp/numra-fastify) |
| `@getnumra/next` | [numra-next](https://github.com/NumraApp/numra-next) |
| `@getnumra/nuxt` | [numra-nuxt](https://github.com/NumraApp/numra-nuxt) |
| `numra/numra-php` | [numra-php](https://github.com/NumraApp/numra-php) — this repo |
| `numra/laravel` | [numra-laravel](https://github.com/NumraApp/numra-laravel) |

Browser:

| Package | Repository |
|---|---|
| `@getnumra/browser` | [numra-browser](https://github.com/NumraApp/numra-browser) |
| `@getnumra/react` | [numra-react](https://github.com/NumraApp/numra-react) |
| `@getnumra/vue` | [numra-vue](https://github.com/NumraApp/numra-vue) |
| `@getnumra/svelte` | [numra-svelte](https://github.com/NumraApp/numra-svelte) |
| `@getnumra/angular` | [numra-angular](https://github.com/NumraApp/numra-angular) |

Documentation for all of them is at [numra.ma/docs](https://numra.ma/docs).

## Licence

MIT
