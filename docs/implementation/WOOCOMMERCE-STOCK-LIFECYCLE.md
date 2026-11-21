# WooCommerce order stock lifecycle — implementation contract

Date: 21 November 2026
Branch: `implementation/phase-0-foundation`

## Confirmed current behavior and defect

Junction currently queues an order when WooCommerce fires `woocommerce_payment_complete`, `woocommerce_order_status_processing`, or `woocommerce_order_status_completed`. The worker then deducts each mapped line from the Junction online location and records one idempotent `wc_order` / `online_sale` movement per Junction product.

That protects duplicate sale hooks, but it does not implement inventory reversals. The existing order queue is keyed only by WooCommerce order ID and carries no event kind, line-item identity, or reversal quantity. A refund/cancellation cannot safely be represented as another sale event.

A further timing risk exists: queue processing is asynchronous, so an order may be cancelled/restocked after its sale hook enqueues but before the worker processes it. The worker currently reads the order lines and deducts them without reconciling the order's intervening stock-restoration events.

## WooCommerce event contract verified against current upstream source

- `woocommerce_restore_order_item_stock` fires after WooCommerce actually restores a cancelled/pending/failed order line. Its arguments are the order item, new stock, old stock, and order. It is per line and is available since WooCommerce 9.1.0.
- `woocommerce_restock_refunded_item` fires after WooCommerce actually restocks a refunded line. Its arguments are product ID, old stock, new stock, order, and product. WooCommerce updates the original order item's `_restock_refunded_items` cumulative metadata before firing this hook.
- `woocommerce_order_refunded` / a payment-refund event alone is not proof of stock restock. The admin refund flow has a separate “Restock refunded items” choice; a refund may intentionally leave inventory unchanged.
- WooCommerce's `_reduced_stock` metadata tracks remaining quantity reduced for an order line. During a partial restock, WooCommerce decreases this value and increments `_restock_refunded_items`; on cancellation restoration, it deletes `_reduced_stock` and fires the per-line restore hook.

Upstream references:
- https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-stock-functions.html
- https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-order-functions.html
- https://woocommerce.github.io/code-reference/files/woocommerce-includes-admin-meta-boxes-views-html-order-items.html

## Required implementation shape

1. Keep the existing sale ingestion as a distinct event; do not overload it to mean “reconcile current order status”.
2. Introduce a durable inventory event queue with a unique immutable event key, event kind, order ID, order-item ID, mapped Junction product ID, positive integer quantity, and retry/claim metadata. A single order can legitimately produce several partial refund-restock events.
3. Enqueue sale events once per eligible order line. Enqueue cancellation/failed/pending restoration only from `woocommerce_restore_order_item_stock`, using the exact restored quantity from WooCommerce's callback values and the order-item ID.
4. Enqueue refund restoration only from `woocommerce_restock_refunded_item`. Resolve the original order item for the product and use the updated cumulative `_restock_refunded_items` quantity as part of the event identity. This distinguishes successive partial restocks of the same order line without trusting a payment-refund event.
5. Add a distinct `OnlineReturn` movement type. Never represent an online return as a POS return or a manual adjustment.
6. Apply each event in one DB transaction: lock the Junction online-location/product row, check the unique movement reference, update quantity, insert the movement, and enqueue aggregate WooCommerce stock sync. The unique reference must identify the specific event/line, not just the order.
7. Ensure the sale and reversal events for the same order line are processed in deterministic order. If a reversal arrives before its sale event has committed, preserve/retry the event rather than silently dropping it or producing an incorrect net balance.
8. Keep WooCommerce's aggregate stock write post-commit and use the existing product-level stock outbox/advisory lock.
9. Add a reconciliation path for missing variation/parent mappings and queue failures. Never silently acknowledge an unmapped line.
10. Preserve the product owner's rule: Junction locations share one ledger; WooCommerce stock is the combined total across all active locations.

## Acceptance tests required before production

- Full order sale followed by cancellation/restock restores exactly the deducted quantity once.
- A failed/pending order that never reduced WooCommerce stock creates no Junction sale or return movement.
- Sale event enqueued, then cancellation/restock before worker execution: final Junction quantity equals the pre-order quantity.
- Partial refund with restock, then a second partial refund with restock: both quantities are restored exactly once.
- Refund without “Restock refunded items” creates no inventory return.
- Duplicate hooks, queue retries, worker crash after ledger commit, and concurrent enqueue/claim do not duplicate or lose movements.
- Variation-managed-by-parent and separately-managed variation mappings resolve to the correct Junction product.
- Missing mapping, inactive online location, and DB failures remain visible and retryable; no partial order is acknowledged.
- The WooCommerce product quantity after each successful event equals the sum of Junction location balances.

## Status

This is a verified defect/design contract, not an implementation claim. The current branch still lacks order cancellation/refund restock reversals. Do not declare the integrated Online Store + POS inventory lifecycle complete or production-ready until this contract is implemented and the acceptance suite passes on a real WooCommerce/MySQL staging installation.
