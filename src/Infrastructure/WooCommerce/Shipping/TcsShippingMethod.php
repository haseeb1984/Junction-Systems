<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\WooCommerce\Shipping;
class TcsShippingMethod extends \WC_Shipping_Method {
    public function __construct($instance_id=0){$this->id='pkstore_'.strtolower('Tcs');$this->instance_id=absint($instance_id);$this->method_title='TCS Express';$this->supports=['shipping-zones','instance-settings'];$this->title='TCS Express';parent::__construct($instance_id);}
    public function calculate_shipping($package=[]):void{$this->add_rate(['id'=>$this->id,'label'=>'TCS Express Standard','cost'=>250]);}
}
