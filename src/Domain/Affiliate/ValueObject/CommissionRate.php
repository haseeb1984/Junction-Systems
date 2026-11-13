<?php
declare(strict_types=1);
namespace PkStore\Domain\Affiliate\ValueObject;
final readonly class CommissionRate {
    public function __construct(public readonly float $percentage) {
        if ($this->percentage < 0 || $this->percentage > 100) throw new \InvalidArgumentException("Commission rate must be 0-100%");
    }
    public function calculate(int $amountPkr): int { return (int) round($amountPkr * ($this->percentage / 100)); }
    public static function default(): self { return new self(10.0); }
}
