<?php
declare(strict_types=1);
namespace JunctionStore\Domain\Affiliate\ValueObject;
final readonly class AffiliateCode {
    public readonly string $value;
    public function __construct(string $value) {
        $clean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $value));
        if (strlen($clean) < 4 || strlen($clean) > 20) throw new \InvalidArgumentException("Invalid affiliate code: [{$value}]");
        $this->value = $clean;
    }
    public static function generate(): self { return new self(strtoupper(substr(bin2hex(random_bytes(5)), 0, 8))); }
    public function __toString(): string { return $this->value; }
}
