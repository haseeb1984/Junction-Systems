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

## Implementation progress — cancellation restock

The branch now includes a first, narrowly-scoped cancellation restoration path:

- Added the `online_return` inventory movement type.
- Registered WooCommerce's per-line `woocommerce_restore_order_item_stock` action, which is emitted only after WooCommerce actually restores that line's stock.
- The handler uses the callback's exact stock delta, resolves a mapped variation before falling back to its parent mapping, locks the Junction online-location stock row, and writes the return movement plus stock-sync outbox intent in one transaction.
- The idempotency reference is `wc_order_restore:{order_id}:{order_item_id}`; repeated callbacks cannot add the same cancellation restock twice.
- WooCommerce aggregate stock is synchronized only after the Junction ledger transaction commits.
- Source smoke guards check that the hook, movement type, and idempotent handler remain wired.

### Remaining limitations — do not treat cancellation/refunds as complete

- The cancellation handler currently logs and surfaces failures but does not yet persist a dedicated retry event. If the DB or mapping is unavailable during the callback, an operator reconciliation is required.
- The current sale queue remains order-level and asynchronous. A sale/restock race needs dedicated staging tests; the final net quantity must be verified for cancellation before the worker runs.
- Explicit refund restocking now has a handler for WooCommerce's `woocommerce_restock_refunded_item` hook. It uses the callback stock delta and the original line's cumulative `_restock_refunded_items` metadata in its idempotency key, so successive partial restocks on a uniquely identifiable line can be recorded separately. The hook omits the original order-item ID; if multiple matching same-product lines make the source ambiguous, the handler fails closed and requires reconciliation.
- All original order lines matching the callback product/variation are counted before selecting a refund line; even a matching line with zero or smaller cumulative restock metadata makes the callback ambiguous. Multiple same-product order lines therefore fail closed rather than allowing metadata filtering to accidentally select the wrong line. Variation stock managed by a parent, successive partial refunds, worker crash/retry, and concurrent event ordering still need runtime acceptance tests.
- This is not production sign-off. The lifecycle remains incomplete until durable retry/reconciliation and partial refund handling are implemented and tested.

## Refund restock implementation

The branch now listens to `woocommerce_restock_refunded_item`, not generic refund/payment events. It records a positive `online_return` movement only after WooCommerce's explicit restock action, using the exact callback stock delta. It resolves the original order line by product/variation identity and cumulative `_restock_refunded_items` metadata. The idempotency key includes order ID, order-item ID, and cumulative restored quantity, so repeated delivery of the same callback is ignored while later partial restocks on that same unique line can be applied.

WooCommerce does not include the original order-item ID in this hook's arguments. If more than one matching order line exists, the implementation deliberately refuses to guess. That event is logged for reconciliation; it is not silently applied to the wrong Junction product.

The cancellation and refund hooks currently perform the ledger write synchronously. They do not yet persist their own dedicated retry queue when mapping/database operations fail. A robust retry queue and administrative reconciliation workflow remain required before production readiness.


## Additional implementation finding — sale/return ordering is a production blocker

A code-path review confirmed a concrete race in the current implementation:

1. A paid/processing/completed hook enqueues an order-level sale job.
2. Before that worker runs, WooCommerce may cancel the order or the merchant may explicitly restock a partial refund.
3. The cancellation/refund handler currently writes an OnlineReturn movement synchronously.
4. The delayed sale worker can subsequently deduct the original full order quantity.

This can leave the Junction online-location balance wrong even though each individual movement is idempotent. The existing stock-sync outbox only serializes writes back to WooCommerce; it does not serialize the source sale and return ledger events.

### Required correction before production

- Replace the order-level sale then independent synchronous return behavior with an order-item event stream.
- Persist immutable event identity, order ID, order-item ID, event kind, mapped Junction product, positive quantity, source cumulative-restock quantity where relevant, and retry/claim state.
- Serialize events for each order item. A return must not be applied until its corresponding sale reduction has been committed; if sale ingestion has not arrived yet, the return stays pending rather than being applied early or discarded.
- Sale quantity must reflect WooCommerce's actual remaining reduced-stock quantity after any completed restocks, not blindly assume the original ordered quantity.
- Reconcile already-committed sale and return event identities before applying a retry. Keep movement insert, stock update, and WooCommerce stock outbox intent in one transaction.
- Do not let an order-level sale worker acknowledge an order while any of its line events are unresolved.
- Add a recovery/reconciliation screen for unmapped, ambiguous, or repeatedly failing events; never silently mark them complete.

### Required race acceptance cases

- Sale queued, cancellation restores stock before worker: final Junction stock equals pre-order stock.
- Sale queued, partial refund restocks before worker: final stock equals pre-order stock minus the still-reduced quantity.
- Sale committed, then cancellation: exactly one matching return restores the sold quantity.
- Sale committed, then two partial refund restocks: each cumulative restock is applied once, and total returns never exceed the original sale.
- Duplicate and out-of-order callbacks, worker crash between claim/commit/ack, mapping failure, and concurrent POS sale must preserve the same final shared ledger balance.

**Status:** This race is not fixed by the current branch. Do not treat cancellation/refund mirroring as production-safe until the durable order-item event stream and staging acceptance cases above are implemented and pass.
