<?php
declare(strict_types=1);
namespace PkStore\Domain\POS\Entity;

enum SyncStatus: string {
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';
}
