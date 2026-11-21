<?php
declare(strict_types=1);
namespace PkStore\Domain\Inventory\Entity;

enum MovementType: string {
    case Sale = 'sale';
    case OnlineSale = 'online_sale';
    case OnlineReturn = 'online_return';
    case POSReturn = 'pos_return';
    case PurchaseReceive = 'purchase_receive';
    case Adjustment = 'adjustment';
    case Transfer = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Damage = 'damage';
    case Opening = 'opening';
}
