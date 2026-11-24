<?php
declare(strict_types=1);
namespace PkStore\Application\POS;
use PkStore\Application\Inventory\AdjustStockUseCase;
use PkStore\Application\POS\Exception\OnlineStockReconciliationPendingException;
use PkStore\Domain\Inventory\Entity\MovementType;
use PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface;
use PkStore\Domain\POS\Entity\POSOrder;
use PkStore\Domain\POS\Repository\{POSOrderRepositoryInterface, POSSessionRepositoryInterface};
use Psr\Log\LoggerInterface;
final class ProcessPOSSaleUseCase {
    public function __construct(
        private readonly POSOrderRepositoryInterface $orderRepo,
        private readonly POSSessionRepositoryInterface $sessionRepo,
        private readonly InventoryProductRepositoryInterface $productRepo,
        private readonly AdjustStockUseCase $adjustStock,
        private readonly LoggerInterface $log,
    ) {}
    public function execute(int $sessionId, int $locationId, array $items, string $paymentMethod, string $customerPhone = '', string $customerName = 'Walk-in', int $discountPkr = 0, ?array $paymentSplit = null, ?string $clientOrderRef = null): POSOrder {
        if (!in_array($paymentMethod, ['cash', 'card', 'jazzcash', 'easypaisa'], true)) {
            throw new \InvalidArgumentException('Unsupported POS payment method.');
        }
        if ($paymentSplit === []) $paymentSplit = null;
        $requestFingerprint = null;
        if ($clientOrderRef !== null) {
            if (!preg_match('/^P-[a-f0-9]{26}$/', $clientOrderRef)) throw new \InvalidArgumentException('POS client order reference is invalid.');
            $requestFingerprint = self::requestFingerprint(
                $sessionId, $locationId, $items, $paymentMethod, $customerPhone,
                $customerName, $discountPkr, $paymentSplit
            );
            $existing = $this->orderRepo->findByRef($clientOrderRef);
            if ($existing) {
                if ($existing->sessionId !== $sessionId || $existing->locationId !== $locationId) {
                    throw new \RuntimeException('POS idempotency reference is already used by another session.');
                }
                if (!$existing->requestFingerprint || !hash_equals($existing->requestFingerprint, $requestFingerprint)) {
                    throw new \RuntimeException('POS idempotency reference was reused with a different or unverifiable sale payload.');
                }
                return $existing;
            }
        }

        $session = $this->sessionRepo->findById($sessionId);
        if (!$session || !$session->isOpen()) throw new \RuntimeException('No open POS session found.');
        if ($session->locationId !== $locationId) throw new \RuntimeException('POS session location does not match the sale location.');

        // Never trust prices or quantities supplied by the browser. Product price
        // is read from the server-side inventory record and quantities must be positive.
        $enriched = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['product_id'], $item['quantity'])) {
                throw new \InvalidArgumentException('Each POS item must include a product and quantity.');
            }
            $productId = filter_var($item['product_id'], FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'], FILTER_VALIDATE_INT);
            if (!$productId || !$quantity || $quantity < 1) {
                throw new \InvalidArgumentException('POS item product and quantity are invalid.');
            }
            $product = $this->productRepo->findById($productId);
            if (!$product || !$product->isActive) {
                throw new \RuntimeException("Product [{$productId}] is unavailable.");
            }
            $enriched[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'quantity' => $quantity,
                'unit_price_pkr' => $product->salePricePkr,
                'discount_pkr' => 0,
            ];
        }

        $subtotal = array_sum(array_map(
            static fn(array $item): int => $item['unit_price_pkr'] * $item['quantity'],
            $enriched
        ));
        if ($discountPkr < 0 || $discountPkr > $subtotal) {
            throw new \InvalidArgumentException('Sale discount must be between zero and the sale subtotal.');
        }

        // Online orders reduce WooCommerce stock before the durable Junction
        // ledger event is applied. Never let a POS sale spend that same stock
        // while an online reduction is still pending or needs operator review.
        $this->assertNoPendingOnlineStockReductions();

        // Keep order, line items, stock ledger/levels and session totals atomic.
        global $wpdb;
        if (!$wpdb instanceof \wpdb || $wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Unable to start the POS sale transaction.');
        }

        try {
            // Refresh and lock the session inside the transaction so concurrent
            // cashiers/sales cannot overwrite each other's tender aggregates.
            $session = $this->sessionRepo->findById($sessionId);
            if (!$session || !$session->isOpen() || $session->locationId !== $locationId) {
                throw new \RuntimeException('POS session is no longer open for this location.');
            }

            $order   = POSOrder::create($sessionId, $locationId, $enriched, $paymentMethod, $customerPhone, $customerName, $discountPkr, $paymentSplit, $clientOrderRef, $requestFingerprint);
            $orderId = $this->orderRepo->save($order);
            if ($orderId < 1) {
                throw new \RuntimeException('Unable to save POS order.');
            }
            $this->orderRepo->saveItems($orderId, $enriched);

            // Lock product/location rows in a stable order shared with online
            // multi-location allocation to reduce cross-channel deadlock risk.
            $stockAdjustments = $enriched;
            usort($stockAdjustments, static fn(array $a, array $b): int => $a['product_id'] <=> $b['product_id']);
            foreach ($stockAdjustments as $item) {
                // Any stock shortage or persistence error aborts the entire sale.
                $this->adjustStock->execute($item['product_id'], $locationId, -$item['quantity'], MovementType::Sale, "POS sale {$order->orderRef}", 'pos_order', $order->orderRef, false);
            }

            $session->recordSale($order->totalPkr, $paymentMethod, $paymentSplit);
            $this->sessionRepo->update($session);

            $savedOrder = $this->orderRepo->findById($orderId);
            if (!$savedOrder) {
                throw new \RuntimeException('Saved POS order could not be reloaded.');
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('Unable to commit POS sale transaction.');
            }
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            // A concurrent retry may have committed the same unique order_ref
            // while this request was processing. Return that durable result.
            if ($clientOrderRef !== null) {
                $existing = $this->orderRepo->findByRef($clientOrderRef);
                if ($existing && $existing->sessionId === $sessionId && $existing->locationId === $locationId) {
                    if ($existing->requestFingerprint && $requestFingerprint
                        && hash_equals($existing->requestFingerprint, $requestFingerprint)) {
                        return $existing;
                    }
                    throw new \RuntimeException('POS idempotency reference was concurrently reused with a different or unverifiable sale payload.');
                }
            }
            throw $e;
        }

        // WooCommerce hooks/API writes are external side effects: do not run them
        // until the POS order, stock ledger and session totals have committed.
        foreach (array_unique(array_column($enriched, 'product_id')) as $productId) {
            try {
                $this->adjustStock->syncProductToWooCommerce((int)$productId);
            } catch (\Throwable $syncError) {
                $this->log->error('POS sale committed but WooCommerce stock sync failed.', [
                    'order_ref' => $savedOrder->orderRef,
                    'product_id' => (int)$productId,
                    'error_class' => get_class($syncError),
                ]);
            }
        }

        $this->log->info('POS sale processed.', ['order_ref'=>$savedOrder->orderRef,'total'=>$savedOrder->totalPkr,'method'=>$paymentMethod]);
        return $savedOrder;
    }

    private function assertNoPendingOnlineStockReductions(): void {
        if (version_compare((string) get_option('pkstore_db_version', '0.0.0'), '2.0.13', '<')) {
            throw new OnlineStockReconciliationPendingException('Online store inventory reconciliation is not ready; POS sales are paused to prevent overselling.');
        }

        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            throw new OnlineStockReconciliationPendingException('Online store inventory reconciliation is unavailable; POS sales are paused to prevent overselling.');
        }

        $eventsTable = $wpdb->prefix . 'pkstore_wc_stock_cycle_events';
        $queueTable = $wpdb->prefix . 'pkstore_wc_order_inventory_queue';
        $cyclesTable = $wpdb->prefix . 'pkstore_wc_stock_cycles';

        $pendingEvents = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$eventsTable} WHERE event_kind='reduction' AND status IN ('pending','processing','needs_review','failed','review')"
        );
        if ($wpdb->last_error !== '' || !is_numeric($pendingEvents)) {
            throw new OnlineStockReconciliationPendingException('Online store inventory reconciliation could not be verified; POS sales are paused.');
        }

        $legacyCycleUuid = '00000000-0000-0000-0000-000000000000';
        $unresolvedQueueRows = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$queueTable} q
             LEFT JOIN {$cyclesTable} c ON c.cycle_uuid=q.cycle_uuid
             LEFT JOIN {$eventsTable} e ON e.event_key=c.reduction_event_key
             WHERE q.status IN ('pending','processing','needs_review')
               AND (q.cycle_uuid=%s OR e.status IS NULL OR e.status<>'processed')",
            $legacyCycleUuid
        ));
        if ($wpdb->last_error !== '' || !is_numeric($unresolvedQueueRows)) {
            throw new OnlineStockReconciliationPendingException('Online store inventory queue could not be verified; POS sales are paused.');
        }

        if ((int) $pendingEvents > 0 || (int) $unresolvedQueueRows > 0) {
            throw new OnlineStockReconciliationPendingException('Online store sales are still being reconciled. Wait briefly and retry the POS sale.');
        }
    }

    public static function requestFingerprint(
        int $sessionId,
        int $locationId,
        array $items,
        string $paymentMethod,
        string $customerPhone,
        string $customerName,
        int $discountPkr,
        ?array $paymentSplit
    ): string {
        $normalizedItems = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['product_id'], $item['quantity'])) {
                throw new \InvalidArgumentException('Each POS item must include a product and quantity.');
            }
            $productId = filter_var($item['product_id'], FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'], FILTER_VALIDATE_INT);
            if (!$productId || !$quantity || $quantity < 1) {
                throw new \InvalidArgumentException('POS item product and quantity are invalid.');
            }
            $normalizedItems[] = ['product_id' => (int)$productId, 'quantity' => (int)$quantity];
        }
        usort($normalizedItems, static function (array $a, array $b): int {
            return ($a['product_id'] <=> $b['product_id']) ?: ($a['quantity'] <=> $b['quantity']);
        });

        $payload = [
            'session_id' => $sessionId,
            'location_id' => $locationId,
            'items' => $normalizedItems,
            'payment_method' => $paymentMethod,
            'customer_phone' => $customerPhone,
            'customer_name' => $customerName,
            'discount_pkr' => $discountPkr,
            'payment_split' => $paymentSplit ? (static function (array $split): array { ksort($split); return $split; })($paymentSplit) : null,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
