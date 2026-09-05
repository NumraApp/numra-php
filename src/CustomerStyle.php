<?php

declare(strict_types=1);

namespace Numra;

/** A behavioural bucket, not a verdict. Reliable, Reactive, Cautious and so on. */
final class CustomerStyle implements \JsonSerializable
{
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $icon,
        public readonly string $color,
        public readonly float $riskSensitivity,
    ) {
    }

    public static function fromArray(?array $r): ?self
    {
        if (!$r || !isset($r['code'])) {
            return null;
        }

        return new self(
            (string) $r['code'],
            (string) ($r['label'] ?? $r['code']),
            (string) ($r['icon'] ?? ''),
            (string) ($r['color'] ?? '#999999'),
            (float) ($r['risk_sensitivity'] ?? 1.0),
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'icon' => $this->icon,
            'color' => $this->color,
            'riskSensitivity' => $this->riskSensitivity,
        ];
    }
}
