<?php
declare(strict_types=1);
namespace PkStore\Domain\FBR\Exception;

final class PralApiException extends \RuntimeException {
    public function __construct(
        string $message,
        public readonly bool $ambiguous = false,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
