<?php
declare(strict_types=1);
namespace JunctionStore\Domain\Payment;
enum PaymentStatus: string { case Pending='pending'; case Approved='approved'; case Failed='failed'; case Refunded='refunded'; case Cancelled='cancelled'; }
