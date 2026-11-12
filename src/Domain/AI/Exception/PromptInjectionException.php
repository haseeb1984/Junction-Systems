<?php
declare(strict_types=1);
namespace JunctionStore\Domain\AI\Exception;
final class PromptInjectionException extends \DomainException { public function __construct(public readonly string $pattern, string $message='Prompt injection detected.') { parent::__construct($message); } }
