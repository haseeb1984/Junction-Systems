<?php
declare(strict_types=1);
namespace PkStore\Domain\POS\Entity;

enum SessionStatus: string {
    case Open = 'open';
    case Closed = 'closed';
}
