<?php

declare(strict_types=1);

namespace Numra;

/**
 * The result of a lookup.
 *
 * Reading it: `riskScore` alone cannot tell a checked-and-clean customer from
 * a complete stranger — both come back low. `isRated` and `confidence` carry
 * that distinction, and `trustScore` already encodes it. On a cash-on-delivery
 * store most buyers are new, so this is the difference that matters.
 */
final class PhoneCheck
{
    public function __construct(
        public readonly string $phone,
        public readonly string $verdict,
        public readonly ?string $verdictSource,
        public readonly int $riskScore,
        public readonly string $riskLevel,
        public readonly float $trustScore,
        public readonly float $confidence,
        public readonly bool $isRated,
        public readonly int $totalEvents,
        public readonly ?CustomerStyle $customerStyle,
        public readonly bool $isBlacklisted,
        public readonly ?string $blacklistedReason,
        public readonly ?string $carrierCode,
        public readonly string $carrierLabel,
        public readonly ?string $lastRiskUpdateAt,
        public readonly int $cacheTtlSeconds,
        /** @var TimelineEntry[]|null */
        public readonly ?array $timeline,
        /** The untouched response, so a field added server-side is reachable
            without waiting for an SDK release. */
        public readonly array $raw,
    ) {
    }

    public static function fromArray(array $r): self
    {
        return new self(
            (string) ($r['phone'] ?? ''),
            (string) ($r['verdict'] ?? 'UNRATED'),
            $r['verdict_source'] ?? null,
            (int) ($r['risk_score'] ?? 0),
            (string) ($r['risk_level'] ?? 'UNRATED'),
            (float) ($r['trust_score'] ?? 0),
            (float) ($r['confidence'] ?? 0),
            (bool) ($r['is_rated'] ?? false),
            (int) ($r['total_events'] ?? 0),
            CustomerStyle::fromArray($r['customer_style'] ?? null),
            (bool) ($r['is_blacklisted'] ?? false),
            $r['blacklisted_reason'] ?? null,
            $r['carrier']['code'] ?? null,
            (string) ($r['carrier']['label'] ?? 'Unknown'),
            $r['last_risk_update_at'] ?? null,
            (int) ($r['cache_ttl_seconds'] ?? 0),
            \is_array($r['timeline'] ?? null)
                ? array_map([TimelineEntry::class, 'fromArray'], $r['timeline'])
                : null,
            $r,
        );
    }

    /**
     * What the browser is allowed to see.
     *
     * A subset, deliberately: `raw` would leak the shape of our ledger,
     * `risk_score_raw` is engine diagnostics, and nothing here names another
     * merchant. The page needs to know what to do about this order, not how
     * the score was built.
     *
     * Identical to `forBrowser()` in @getnumra/core, so @getnumra/react renders a
     * PHP backend's response with no adapter in between.
     */
    public function toBrowserArray(): array
    {
        return [
            'phone' => $this->phone,
            'verdict' => $this->verdict,
            'riskLevel' => $this->riskLevel,
            'riskScore' => $this->riskScore,
            'trustScore' => $this->trustScore,
            'confidence' => $this->confidence,
            'isRated' => $this->isRated,
            'isBlacklisted' => $this->isBlacklisted,
            'customerStyle' => $this->customerStyle?->jsonSerialize(),
        ];
    }
}
