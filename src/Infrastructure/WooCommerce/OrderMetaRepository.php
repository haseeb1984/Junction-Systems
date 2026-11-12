<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\WooCommerce;
final class OrderMetaRepository {
    public function saveTrackingNumber(string $oid,string $tn,string $cc):void{$o=wc_get_order((int)$oid);if(!($o instanceof \WC_Order))return;$o->update_meta_data('_tracking_'.$cc,$tn);$o->update_meta_data('_tracking_courier',$tn);$o->update_meta_data('_booked_courier',$cc);$o->add_order_note("[Junction Store] Booked with ".strtoupper($cc).". Tracking: {$tn}");$o->save_meta_data();}
    public function getTrackingNumber(string $oid):string{$o=wc_get_order((int)$oid);return($o instanceof \WC_Order)?(string)$o->get_meta('_tracking_courier'):'';}
    public function getBookedCourier(string $oid):string{$o=wc_get_order((int)$oid);return($o instanceof \WC_Order)?(string)$o->get_meta('_booked_courier'):'';}
}
