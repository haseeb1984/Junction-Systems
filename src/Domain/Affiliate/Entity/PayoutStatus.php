<?php
declare(strict_types=1);

namespace PkStore\Domain\Affiliate\Entity;

enum PayoutStatus: string {
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
