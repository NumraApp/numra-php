# Contributing to numra/numra-php

Patches are welcome. This is a small package with a large blast radius — it
holds a credential that reads a shared fraud ledger and spends a merchant's
paid quota — so the bar for a change is a test that would have caught the bug,
not a convincing description of it.

## Running the tests

```bash
composer install && vendor/bin/phpunit
```

PHP 8.1 or newer, with `ext-curl` and `ext-json`. That floor is declared in
`composer.json` and the release pipeline runs the suite on 8.1, 8.2, 8.3 and
8.4 — "PHP 8.1 or newer" is meant to be a fact rather than an aspiration, so a
change that quietly needs 8.2 syntax will fail there.

`tests/FakeTransport.php` and `tests/RecordingTransport.php` stand in for the
network, so the suite never makes a request and never needs a key. Add
dependencies only with a very good reason: a fraud client that drags a tree of
transitive packages into a merchant's checkout is a supply-chain surface
nobody asked for, and `require` currently lists nothing but PHP and two
extensions.

## Every change needs a test

Every package in this family ships a regression suite, and it is the only
thing standing between a refactor and a silent behavioural change. So:

- A bug fix comes with a test that fails before it and passes after.
- A new method or field comes with a test that exercises it.
- A change to existing behaviour comes with the changed assertion, and the
  reason for the change in the commit message.

`tests/HardeningTest.php` and `tests/HandlersTest.php` encode decisions that
look arbitrary until you know what they are for — deny-by-default in
`Handlers`, constant-time signature comparison, the 300-second replay window,
`QUOTA_EXCEEDED` never being retried. If a change makes one of those fail, the
fix is almost never to relax the test.

## Which repository your fix belongs in

These repositories are split out of a single monorepo. What you see here is
one package of twelve.

- `Numra\Handlers` is the PHP twin of `createHandlers` in
  [numra-js-core](https://github.com/NumraApp/numra-js-core). A change to what
  either one decides — who is authorised, which fields reach the browser, how
  an upstream failure is translated — needs the same change in the other, or
  a PHP backend and a Node backend stop agreeing about the same phone number.
  Say in the pull request that the twin needs it too.
- Anything Laravel-shaped — the service provider, the route macro, the
  facade, the artisan command — belongs in
  [numra-laravel](https://github.com/NumraApp/numra-laravel), which is a thin
  layer over this package.
- The response `Handlers::check()` returns is consumed by `@numra/react` and
  its siblings. Changing its shape is a change to
  [numra-browser](https://github.com/NumraApp/numra-browser) as well.

## Versions and tags

There is deliberately no `version` field in `composer.json` — Packagist takes
the version from the git tag, and `composer validate --strict` rejects a
declared one. `Numra::VERSION` is the constant that matters; the release
workflow refuses a tag that disagrees with it.

`numra/numra-php` must be tagged and on Packagist **before** `numra/laravel`
is tagged, or every installer of the Laravel package gets an unsolvable
requirement.

## The conformance gate

```bash
node scripts/openapi-conformance.js
```

Node is used only to run this one release gate; the package itself needs
nothing but PHP. It checks the package against the API contract and against
itself, and fails by default when no contract is vendored, on purpose: a
conformance step that goes green having compared nothing manufactures exactly
the assurance it exists to provide. Point `NUMRA_OPENAPI` at a copy of the
spec, or drop it at one of the paths the script lists, to make it run for
real.

## House style

British spelling, no emoji in headings, and prose that says what a thing does
rather than how good it is. Comments explain the decision, not the syntax.

## Reporting a bug

Open an issue with the package version, the PHP version, and the smallest
reproduction you can manage. **A security vulnerability is not a bug report**
— see [SECURITY.md](SECURITY.md) and mail it privately instead.
