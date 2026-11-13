<?php
declare(strict_types=1);
namespace PkStore\Domain\Payment;
enum PaymentStatus: string { case Pending='pending'; case Approved='approved'; case Failed='failed'; case Refunded='refunded'; case Cancelled='cancelled'; }
