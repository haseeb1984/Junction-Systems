<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\WooCommerce;

use PkStore\Container\ServiceContainer;
use PkStore\Core\Feature\FeatureManager;

final class GatewayRegistry {
    public function __construct(
        private readonly ServiceContainer $container,
        private readonly FeatureManager $features
    ) {}

    public function register(): void {
        add_filter('woocommerce_payment_gateways', function (array $gateways): array {
            $available = [
                'payment_jazzcash' => \PkStore\Infrastructure\Payment\JazzCashGateway::class,
                'payment_easypaisa' => \PkStore\Infrastructure\Payment\EasyPaisaGateway::class,
                'payment_foree' => \PkStore\Infrastructure\Payment\Foree\ForeePayGateway::class,
            ];

            foreach ($available as $feature => $gatewayClass) {
                if ($this->features->isEnabled($feature) && class_exists($gatewayClass)) {
                    $gateways[] = $gatewayClass;
                }
            }

            return array_values(array_unique($gateways));
        });
    }
}
