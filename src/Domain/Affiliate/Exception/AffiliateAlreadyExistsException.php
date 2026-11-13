<?php
declare(strict_types=1);
namespace PkStore\Domain\Affiliate\Exception;
final class AffiliateAlreadyExistsException extends AffiliateException { public function __construct(string $email) { parent::__construct("Affiliate already registered: [{$email}]"); } }
