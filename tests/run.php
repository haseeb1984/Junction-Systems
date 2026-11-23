<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Domain/POS/Entity/POSSession.php';

use PkStore\Domain\POS\Entity\POSSession;

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

fwrite(STDOUT, "{$passed} POS session checks passed.\n");
