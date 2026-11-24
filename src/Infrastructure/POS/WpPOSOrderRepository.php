<?php
declare(strict_types=1);

namespace PkStore\Infrastructure\POS;

use PkStore\Domain\POS\Entity\{POSOrder, SyncStatus};
use PkStore\Domain\POS\Repository\POSOrderRepositoryInterface;

final class WpPOSOrderRepository implements POSOrderRepositoryInterface {
    public function __construct(private readonly \wpdb $db) {}

    private function t(): string { return $this->db->prefix . 'pkstore_pos_orders'; }
    private function ti(): string { return $this->db->prefix . 'pkstore_pos_order_items'; }

    public function save(POSOrder $order): int {
        $inserted = $this->db->insert($this->t(), [
            'session_id' => $order->sessionId,
            'location_id' => $order->locationId,
            'order_ref' => $order->orderRef,
            'request_fingerprint' => $order->requestFingerprint,
            'subtotal_pkr' => $order->subtotalPkr,
            'discount_pkr' => $order->discountPkr,
            'total_pkr' => $order->totalPkr,
            'payment_method' => $order->paymentMethod,
            'payment_split' => $order->paymentSplit ? wp_json_encode($order->paymentSplit) : null,
            'customer_phone' => $order->customerPhone,
            'customer_name' => $order->customerName,
            'receipt_sent' => 0,
            'sync_status' => 'pending',
            'wc_order_id' => null,
            'created_at' => $order->createdAt->format('Y-m-d H:i:s'),
            'synced_at' => null,
        ]);
        if ($inserted === false || (int) $this->db->insert_id < 1) {
            throw new \RuntimeException('Unable to persist POS order.');
        }
        return (int) $this->db->insert_id;
    }

    public function update(POSOrder $order): void {
        $updated = $this->db->update($this->t(), [
            'sync_status' => $order->getSyncStatus()->value,
            'wc_order_id' => $order->wcOrderId,
            'synced_at' => $order->getSyncStatus() === SyncStatus::Synced ? current_time('mysql') : null,
        ], ['id' => $order->id]);
        if ($updated !== 1) {
            throw new \RuntimeException('Unable to persist POS WooCommerce sync state.');
        }
    }

    public function findById(int $id): ?POSOrder {
        $row = $this->db->get_row(
            $this->db->prepare("SELECT * FROM {$this->t()} WHERE id=%d", $id),
            ARRAY_A
        );
        $this->assertReadSucceeded('POS order lookup');
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByRef(string $reference): ?POSOrder {
        $row = $this->db->get_row(
            $this->db->prepare("SELECT * FROM {$this->t()} WHERE order_ref=%s", $reference),
            ARRAY_A
        );
        $this->assertReadSucceeded('POS order reference lookup');
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findPending(): array {
        $rows = $this->db->get_results(
            "SELECT * FROM {$this->t()} WHERE sync_status='pending' ORDER BY created_at ASC LIMIT 50",
            ARRAY_A
        );
        $this->assertReadSucceeded('pending POS order queue lookup');
        return array_map(fn(array $row): POSOrder => $this->hydrate($row), is_array($rows) ? $rows : []);
    }

    public function findBySession(int $sessionId): array {
        $rows = $this->db->get_results(
            $this->db->prepare("SELECT * FROM {$this->t()} WHERE session_id=%d ORDER BY created_at DESC", $sessionId),
            ARRAY_A
        );
        $this->assertReadSucceeded('POS session order lookup');
        return array_map(fn(array $row): POSOrder => $this->hydrate($row), is_array($rows) ? $rows : []);
    }

    public function saveItems(int $orderId, array $items): void {
        foreach ($items as $item) {
            $inserted = $this->db->insert($this->ti(), [
                'order_id' => $orderId,
                'product_id' => $item['product_id'],
                'sku' => $item['sku'],
                'product_name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit_price_pkr' => $item['unit_price_pkr'],
                'discount_pkr' => $item['discount_pkr'] ?? 0,
                'total_pkr' => $item['unit_price_pkr'] * $item['quantity'],
            ]);
            if ($inserted === false) {
                throw new \RuntimeException('Unable to persist POS order line item.');
            }
        }
    }

    private function hydrate(array $row): POSOrder {
        $order = new POSOrder(
            (int) $row['id'],
            (int) $row['session_id'],
            (int) $row['location_id'],
            $row['order_ref'],
            (int) $row['subtotal_pkr'],
            (int) $row['discount_pkr'],
            (int) $row['total_pkr'],
            $row['payment_method'],
            $row['payment_split'] ? json_decode($row['payment_split'], true) : null,
            $row['customer_phone'],
            $row['customer_name'],
            (bool) $row['receipt_sent'],
            SyncStatus::from($row['sync_status']),
            $row['wc_order_id'] ? (int) $row['wc_order_id'] : null,
            new \DateTimeImmutable($row['created_at']),
            $row['request_fingerprint'] ?? null
        );
        $items = $this->db->get_results(
            $this->db->prepare(
                "SELECT product_id, sku, product_name AS name, quantity, unit_price_pkr, discount_pkr, total_pkr
                 FROM {$this->ti()} WHERE order_id=%d ORDER BY id ASC",
                (int) $row['id']
            ),
            ARRAY_A
        );
        $this->assertReadSucceeded('POS order line-item lookup');
        $order->setItems(is_array($items) ? $items : []);
        return $order;
    }

    private function assertReadSucceeded(string $operation): void {
        if ($this->db->last_error !== '') {
            throw new \RuntimeException('Database error during ' . $operation . '.');
        }
    }
}
