<?php
declare(strict_types=1);
namespace PkStore\Domain\Logistics\DTO;
enum TrackingStatus: string {
    case Booked='booked'; case PickedUp='picked_up'; case InTransit='in_transit';
    case OutForDelivery='out_for_delivery'; case Delivered='delivered';
    case FailedAttempt='failed_attempt'; case Returned='returned';
    case Cancelled='cancelled'; case Exception='exception';
    public static function fromCourierCode(string $code, string $courier):self {
        return match($courier){
            'tcs'=>match(strtoupper($code)){'BOK','BOOKED'=>self::Booked,'PU','PICKED'=>self::PickedUp,'IT','OFD','INTRANSIT'=>self::InTransit,'OFD2','OUTFORDELIVERY'=>self::OutForDelivery,'DL','DELIVERED'=>self::Delivered,'FA','FAILED'=>self::FailedAttempt,'RTO','RETURNED'=>self::Returned,'CAN','CANCELLED'=>self::Cancelled,default=>self::Exception},
            'leopards'=> match((int)$code){1=>self::Booked,2=>self::PickedUp,3,4=>self::InTransit,5=>self::OutForDelivery,6=>self::Delivered,7=>self::FailedAttempt,8=>self::Returned,9=>self::Cancelled,default=>self::Exception},
            'trax'=> match(strtolower($code)){'created'=>self::Booked,'picked_up'=>self::PickedUp,'in_transit'=>self::InTransit,'out_for_delivery'=>self::OutForDelivery,'delivered'=>self::Delivered,'delivery_attempted'=>self::FailedAttempt,'rto_initiated','rto_delivered'=>self::Returned,'cancelled'=>self::Cancelled,default=>self::Exception},
            default=>self::Exception,
        };
    }
    public function toWooCommerceStatus():string { return match($this){self::Booked,self::PickedUp=>'wc-shipped',self::InTransit,self::OutForDelivery=>'wc-out-for-delivery',self::Delivered=>'completed',self::FailedAttempt=>'wc-delivery-failed',self::Returned=>'wc-returned',self::Cancelled=>'cancelled',self::Exception=>'on-hold'}; }
}
