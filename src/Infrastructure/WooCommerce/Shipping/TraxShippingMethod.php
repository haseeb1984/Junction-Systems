<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\WooCommerce\Shipping;
class TraxShippingMethod extends \WC_Shipping_Method {
    public function __construct($instance_id=0){$this->id='pkstore_'.strtolower('Trax');$this->instance_id=absint($instance_id);$this->method_title='Trax (Sonic)';$this->supports=['shipping-zones','instance-settings'];$this->title='Trax (Sonic)';parent::__construct($instance_id);}
    public function calculate_shipping($package=[]):void{$this->add_rate(['id'=>$this->id,'label'=>'Trax (Sonic) Standard','cost'=>210]);}
}
