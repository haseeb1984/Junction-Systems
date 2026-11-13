<?php
declare(strict_types=1);
namespace PkStore\License\Exception;

class LicenseException extends \RuntimeException {}
class LicenseExpiredException    extends LicenseException {}
class LicenseDomainMismatch      extends LicenseException {}
class LicenseInvalidException    extends LicenseException {}
class LicenseFeatureNotAvailable extends LicenseException {}
