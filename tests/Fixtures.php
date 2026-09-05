<?php

declare(strict_types=1);

namespace Numra\Tests;

/** The same LOOKUP_OK the JS suite uses, so the two families are compared
    against one payload rather than two that drifted apart. */
final class Fixtures
{
    public const LOOKUP_OK = [
        'ok' => true,
        'phone' => '+212600000000',
        'country' => 'MA',
        'carrier' => ['code' => 'IAM', 'label' => 'Maroc Telecom'],
        'verdict' => 'RATED',
        'verdict_source' => 'events',
        'risk_score' => 72,
        'risk_score_raw' => 68.4,
        'risk_level' => 'HIGH',
        'trust_score' => 28,
        'confidence' => 61,
        'is_rated' => true,
        'total_events' => 9,
        'customer_style' => [
            'code' => 'reactive', 'label' => 'Reactive', 'icon' => '⚡',
            'color' => '#F26D6D', 'risk_sensitivity' => 1.2,
        ],
        'is_blacklisted' => false,
        'blacklisted_reason' => null,
        'last_risk_update_at' => '2026-09-01T10:00:00.000Z',
        'cache_ttl_seconds' => 3600,
        'timeline' => null,
    ];
}
