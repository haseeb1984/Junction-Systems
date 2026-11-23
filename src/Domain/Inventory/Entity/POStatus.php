<?php
declare(strict_types=1);
namespace PkStore\Domain\Inventory\Entity;
enum POStatus: string {
    case Draft = 'draft';
    case Sent = 'sent';
    case PartiallyReceived = 'partial';
    case Received = 'received';
    case Cancelled = 'cancelled';
}
