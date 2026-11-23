<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Domain/POS/Entity/POSSession.php';
require_once dirname(__DIR__) . '/src/Domain/POS/Entity/POSOrder.php';

use PkStore\Domain\POS\Entity\POSSession;
use PkStore\Domain\POS\Entity\POSOrder;

$passed = 0;
$failed = [];

function check(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        return;
    }
    $failed[] = $message;
}

function expectException(string $expected, callable $callback, string $message): void {
    try {
        $callback();
        check(false, $message . ' (no exception thrown)');
    } catch (Throwable $error) {
        check($error instanceof $expected, $message . ' (got ' . get_class($error) . ')');
    }
}

$order = POSOrder::create(11, 2, [
    ['product_id'=>4,'sku'=>'SKU-4','name'=>'Sample item','quantity'=>2,'unit_price_pkr'=>500,'discount_pkr'=>100],
], 'cash', '', 'Walk-in', 50, null, 'POS-TEST-0001');
check($order->subtotalPkr === 1000, 'POS order calculates gross subtotal');
check($order->discountPkr === 50, 'POS order retains order-level discount');
check($order->totalPkr === 850, 'POS order applies line and order discounts');
check($order->orderRef === 'POS-TEST-0001', 'POS order preserves stable client reference');
check(count($order->getItems()) === 1, 'POS order retains line items');
expectException(InvalidArgumentException::class, fn() => POSOrder::create(0, 2, [['quantity'=>1,'unit_price_pkr'=>10]], 'cash'), 'POS order rejects invalid session');
expectException(InvalidArgumentException::class, fn() => POSOrder::create(11, 0, [['quantity'=>1,'unit_price_pkr'=>10]], 'cash'), 'POS order rejects invalid location');
expectException(InvalidArgumentException::class, fn() => POSOrder::create(11, 2, [], 'cash'), 'POS order rejects empty items');
expectException(InvalidArgumentException::class, fn() => POSOrder::create(11, 2, [['quantity'=>0,'unit_price_pkr'=>10]], 'cash'), 'POS order rejects zero quantity');
expectException(InvalidArgumentException::class, fn() => POSOrder::create(11, 2, [['quantity'=>1,'unit_price_pkr'=>10,'discount_pkr'=>11]], 'cash'), 'POS order rejects line discount above line gross');
expectException(InvalidArgumentException::class, fn() => POSOrder::create(11, 2, [['quantity'=>1,'unit_price_pkr'=>10]], 'cash', '', 'Walk-in', 11), 'POS order rejects order discount above remaining total');
expectException(InvalidArgumentException::class, fn() => POSOrder::create(11, 2, [['quantity'=>1,'unit_price_pkr'=>10]], 'crypto'), 'POS order rejects unsupported payment method');
expectException(InvalidArgumentException::class, fn() => POSOrder::create(11, 2, [['quantity'=>1,'unit_price_pkr'=>100]], 'cash', '', 'Walk-in', 0, ['cash'=>60,'card'=>30]), 'POS order validates split tender total');
expectException(InvalidArgumentException::class, fn() => POSOrder::create(11, 2, [['quantity'=>1,'unit_price_pkr'=>100]], 'cash', '', 'Walk-in', 0, null, 'BAD REF!'), 'POS order validates stable reference format');

$session = POSSession::open(2, 7, 1000);
check($session->isOpen(), 'new session starts open');
check($session->getTotalSalesPkr() === 0, 'new session starts with zero sales');

$session->recordSale(500, 'cash');
check($session->getTotalSalesPkr() === 500, 'cash sale increments total sales');
check($session->getTotalCashPkr() === 500, 'cash sale increments cash tender');

$session->recordSale(200, 'card');
check($session->getTotalCardPkr() === 200, 'card sale increments card tender');

$split = POSSession::open(2, 7, 0);
$split->recordSale(1000, 'cash', ['cash' => 600, 'card' => 250, 'jazzcash' => 100, 'easypaisa' => 50]);
check($split->getTotalSalesPkr() === 1000, 'split sale increments total sales once');
check($split->getTotalCashPkr() === 600, 'split sale records cash portion');
check($split->getTotalCardPkr() === 250, 'split sale records card portion');
check($split->getTotalJazzcashPkr() === 100, 'split sale records JazzCash portion');
check($split->getTotalEasypaisaPkr() === 50, 'split sale records EasyPaisa portion');

expectException(InvalidArgumentException::class, fn() => $split->recordSale(100, 'cash', ['cash' => 60]), 'split tender must equal sale total');
expectException(InvalidArgumentException::class, fn() => $split->recordSale(100, 'crypto'), 'unsupported tender is rejected');
expectException(InvalidArgumentException::class, fn() => $split->recordSale(100, 'cash', ['cash' => -1, 'card' => 101]), 'negative split amount is rejected');
expectException(InvalidArgumentException::class, fn() => $split->recordSale(-1, 'cash'), 'negative sale total is rejected');
expectException(InvalidArgumentException::class, fn() => POSSession::open(0, 7, 0), 'invalid location is rejected');
expectException(InvalidArgumentException::class, fn() => POSSession::open(2, 0, 0), 'invalid cashier is rejected');
expectException(InvalidArgumentException::class, fn() => POSSession::open(2, 7, -1), 'negative opening cash is rejected');
expectException(InvalidArgumentException::class, fn() => $split->close(-1), 'negative closing cash is rejected');

$session->close(1450);
check(!$session->isOpen(), 'closing session changes status');
check($session->getCashVariance() === -50, 'cash variance includes opening cash and cash tenders');
expectException(LogicException::class, fn() => $session->recordSale(10, 'cash'), 'closed session rejects additional sales');

if ($failed !== []) {
    foreach ($failed as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    fwrite(STDERR, count($failed) . " test(s) failed; {$passed} passed.\n");
    exit(1);
}

fwrite(STDOUT, "{$passed} POS domain checks passed.\n");
