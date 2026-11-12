<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\WooCommerce;
use JunctionStore\Container\ServiceContainer;
final class GatewayRegistry {
    public function __construct(private readonly ServiceContainer $c){}
    public function register():void{add_filter('woocommerce_payment_gateways',function(array $g):array{$g[]=\JunctionStore\Infrastructure\Payment\JazzCashGateway::class;$g[]=\JunctionStore\Infrastructure\Payment\EasyPaisaGateway::class;return $g;});}
}
