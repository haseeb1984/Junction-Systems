<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\WooCommerce;
use PkStore\Container\ServiceContainer;
final class GatewayRegistry {
    public function __construct(private readonly ServiceContainer $c){}
    public function register():void{add_filter('woocommerce_payment_gateways',function(array $g):array{$g[]=\PkStore\Infrastructure\Payment\JazzCashGateway::class;$g[]=\PkStore\Infrastructure\Payment\EasyPaisaGateway::class;return $g;});}
}
