<?php

declare(strict_types=1);

namespace Numra;

/** One recorded outcome. Only present when includeTimeline was asked for. */
final class TimelineEntry
{
    public function __construct(
        public readonly string $eventType,
        public readonly ?float $orderTotal,
        public readonly ?string $currency,
        public readonly ?string $region,
        public readonly ?string $note,
        public readonly ?string $siteUrl,
        public readonly string $createdAt,
    ) {
    }

    public static function fromArray(array $t): self
    {
        return new self(
            (string) ($t['event_type'] ?? ''),
            isset($t['order_total']) ? (float) $t['order_total'] : null,
            $t['currency'] ?? null,
            $t['region'] ?? null,
            $t['note'] ?? null,
            $t['site_url'] ?? null,
            (string) ($t['created_at'] ?? ''),
        );
    }
}
