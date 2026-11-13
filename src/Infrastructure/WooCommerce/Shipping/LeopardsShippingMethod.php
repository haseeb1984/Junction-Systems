<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\WooCommerce\Shipping;
class LeopardsShippingMethod extends \WC_Shipping_Method {
    public function __construct($instance_id=0){$this->id='pkstore_'.strtolower('Leopards');$this->instance_id=absint($instance_id);$this->method_title='Leopards Courier';$this->supports=['shipping-zones','instance-settings'];$this->title='Leopards Courier';parent::__construct($instance_id);}
    public function calculate_shipping($package=[]):void{$this->add_rate(['id'=>$this->id,'label'=>'Leopards Courier Standard','cost'=>220]);}
}
