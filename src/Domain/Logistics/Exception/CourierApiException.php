<?php
declare(strict_types=1);
namespace PkStore\Domain\Logistics\Exception;
final class CourierApiException extends \RuntimeException { public function __construct(string $message, public readonly string $courierCode, public readonly int $httpStatus=0, public readonly string $apiErrorCode='', ?\Throwable $previous=null) { parent::__construct($message,0,$previous); } public function isRetryable():bool{return $this->httpStatus===0||($this->httpStatus>=500&&$this->httpStatus<600);} }
