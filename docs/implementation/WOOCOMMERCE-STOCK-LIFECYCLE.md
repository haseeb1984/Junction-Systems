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

This section records the original defect and acceptance contract before the return handlers and durable retry queue were implemented. The current implementation status and remaining blockers are recorded in the later progress sections below.

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

At the time of this initial refund-handler implementation, cancellation and refund hooks wrote synchronously. This was superseded by the durable return-event queue documented below; administrative reconciliation remains outstanding.


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

**Implementation update:** The current branch now serializes sale, cancellation and refund handlers with one per-order MySQL advisory lock. Sale ingestion re-reads the order after acquiring the lock, skips cancelled/failed orders, and deducts only ordered quantity less WooCommerce's cumulative explicitly-restocked refund quantity. A return callback checks whether the matching Junction sale movement is already committed: if not, it defers the return because the later sale ingestion will account for the already-restocked quantity; if yes, it records the return movement idempotently. This closes the identified sale-before/after-return ordering race for the supported WooCommerce callbacks, subject to staging verification.

**Remaining production blockers:** Return callback failures are still synchronous and do not yet have their own durable retry/reconciliation queue. Ambiguous refund line mapping fails closed and requires operator intervention. The acceptance cases above, worker crash/retry behavior, MySQL advisory-lock behavior under the hosting topology, and concurrent POS sales still require runtime/staging tests. Do not declare production readiness until those tests pass and return-event recovery is implemented.


## Durable return-event recovery implementation

The current branch now adds a dedicated `pkstore_wc_return_inventory_queue` table (migration `2.0.4`) and a five-minute worker for cancellation and explicit refund restocks.

- WooCommerce callbacks now persist immutable return event keys instead of writing the Junction ledger synchronously.
- Cancellation event identity includes order ID, order-item ID, and restored quantity.
- Refund event identity includes order ID, product ID, optional uniquely resolved order-item ID, and cumulative restocked quantity. Ambiguous same-product order lines remain queued and fail closed rather than guessing.
- Queue jobs have claim/recovery state, attempts, capped exponential retry, and stale-claim recovery. A worker crash after ledger commit is safe because the ledger movement references remain idempotent; the queue retries and then acknowledges.
- The worker calls the same order-locked inventory use case. If the matching sale movement is not committed yet, the return event stays pending and retries; it is not discarded. A cancelled/failed/pending order with no sale movement is terminal for a cancellation return. A refunded payment is **not** proof that stock was restored: WooCommerce allows refunds without selecting “Restock refunded items”. Therefore sale ingestion still records the original sale for refunded orders, and a refund-return event cannot be acknowledged until that sale movement exists. A refund with no stock-restock callback creates no return movement.
- Sale ingestion records the original sale quantity; actual cancellation/refund stock restoration is represented by separate durable return movements. This prevents the same partial refund from being deducted from the sale quantity and then added again as a return.

### Remaining blockers

- A refund event whose corresponding sale movement never arrives remains queued and requires reconciliation; payment status alone cannot safely resolve it.
- Ambiguous refund lines currently retry indefinitely; a Junction admin reconciliation screen/dead-letter workflow is still required.
- If WordPress/MySQL is unavailable at the exact moment the WooCommerce callback fires, the callback cannot persist the event; monitoring and source-event reconciliation are still required.
- Re-payment/reopening of a previously reduced and restored order may represent a new stock-reduction cycle; sale movement identity currently remains order-level and needs an explicit lifecycle-cycle design before that scenario is certified.
- The queue and stock behavior have not yet been runtime-tested against WooCommerce/MySQL. Verify cancellation-before-sale, refund-before-sale, partial refunds, full refunds, repeated callbacks, stale worker recovery, concurrent workers, and POS sales in staging.

**Status:** Durable retry is now implemented for persisted return callbacks, but production sign-off remains blocked on runtime acceptance, unresolved-event reconciliation UI, and the order re-payment lifecycle case.


## Return-worker lease fencing — implementation update

A further concurrency review found that the return queue had stale-claim recovery but no per-claim ownership token. A worker that exceeded the ten-minute lease could therefore finish after another worker reclaimed the same row and potentially acknowledge or reschedule the newer worker's claim.

The return queue now persists a UUID `claim_token` for each claim. Stale-claim recovery clears the token; completion and retry updates are conditional on the exact token held by that worker. A worker whose lease has been replaced cannot delete or reschedule the newer claim. Migration `2.0.5` adds the nullable token column to existing installations.

The ledger operation remains independently idempotent, so a stale worker that finishes an already-applied movement cannot duplicate the inventory movement. This change has source-level smoke guards only; it still requires multi-worker/lease-expiry runtime testing.


## Order-worker lease fencing — implementation update

A second queue-worker race was found during source review. The order queue previously used a generation value to detect duplicate hooks arriving during processing, but stale-claim recovery did not change that generation. If a worker exceeded the ten-minute lease and another worker reclaimed the same row, the old worker could still complete or reschedule the row because both workers shared the same generation.

Order queue claims now receive a persisted UUID \`claim_token\`. Stale-claim recovery clears the token; each new claim writes a fresh token; completion and retry require the exact token as well as the expected generation and processing state. A worker whose lease was reclaimed cannot delete or reschedule the newer worker's row. Generation handling remains in place to release a newer duplicate-hook event for another idempotent pass.

Migration 2.0.6 re-runs the order queue schema installer and verifies the new column. A source-level smoke guard checks the fencing contract. No PHP execution, database migration, stale-lease simulation, or multi-worker test has been run yet; those remain part of the temporary-public CI/runtime batch.


## POS-to-WooCommerce order mirroring — implementation update

The POS sale already commits the Junction stock movement, location quantity, order lines, and cashier-session totals before WooCommerce synchronization. The WooCommerce mirror must therefore not create a second inventory deduction.

The POS sync worker now:
- serializes each POS order with a per-order MySQL advisory lock;
- uses the immutable POS order reference as WooCommerce order metadata, so a retry can find a WooCommerce order created before the POS-to-WooCommerce link was saved;
- requires PKR store currency because Junction POS prices are stored as PKR and no FX conversion contract exists;
- requires every POS line to resolve to a mapped WooCommerce product;
- writes the POS origin, tender, original Junction product/location, and already-deducted stock markers before payment completion;
- marks the WooCommerce order paid with the POS reference as its transaction identifier, then persists the WooCommerce order ID on the POS record;
- skips mirrored POS orders in the online-sale ingestion hook, preventing the POS stock movement from being deducted again;
- on cancellation or explicit refund restock, uses the line's persisted Junction product/location metadata and verifies the original \`pos_order\` sale movement before applying the return to the same location.

An unpaid WooCommerce order found during retry has its line items rebuilt from the immutable POS record before payment completion. A paid order is linked only after its line-item product/variation quantities and total match the POS source. Multiple matching WooCommerce orders, missing mappings, currency mismatch, or inconsistent paid orders fail closed and remain pending for operator investigation.

This is source implementation, not runtime acceptance. Required tests include duplicate sync requests, crash after WooCommerce order creation, crash after payment completion but before POS linkage, variation products, unmapped items, cancellation and refund restock at a non-online POS location, and ensuring the WooCommerce stock quantity remains the combined Junction stock snapshot without a second deduction. The actual WooCommerce/MySQL/HPOS runtime matrix is still outstanding.


### POS mirror restock bookkeeping follow-up

WooCommerce's native cancellation and explicit refund-restock flows inspect per-order-item `_reduced_stock` bookkeeping before emitting their actual-restock callbacks. Because Junction has already deducted the shared stock ledger and aggregate WooCommerce stock snapshot, the POS mirror does not invoke a second WooCommerce stock reduction. It now records the POS line quantity in `_reduced_stock` as lifecycle bookkeeping only. Paid-order recovery verifies that this marker equals the immutable POS line quantity alongside the Junction product, original location and POS reference metadata.

This is intended to let WooCommerce's own cancellation/refund flows identify the quantity to restore, after which Junction's durable return queue applies the corresponding ledger movement to the original POS location and republishes the combined stock snapshot. It still needs real WooCommerce-version/HPOS runtime acceptance; product stock-management settings and the exact native restore behavior must be exercised before production use.


## Implementation progress — sale trigger hardened

The online sale queue trigger now listens to `woocommerce_reduce_order_stock`, which runs after WooCommerce's order stock-reduction pass, instead of enqueueing from generic payment-complete or processing/completed status transitions.

- Payment receipt and order status changes alone no longer create Junction online-sale queue events.
- The callback validates the WooCommerce order object and skips Junction POS mirror orders, whose inventory was already deducted by the POS ledger.
- Existing durable queue idempotency, retry, claim fencing and post-commit aggregate stock synchronization remain in place.
- Source smoke guards assert the actual stock-reduction hook is registered and the generic payment/status triggers are absent.

### Important remaining limitations

- This change does not yet implement per-cycle UUID identity. The ledger still references online sale movements by order ID, so a later genuine reduction after a full restore of the same order can still be incorrectly treated as a duplicate.
- Runtime acceptance must confirm the supported WooCommerce versions invoke `woocommerce_reduce_order_stock` after successful item reductions, including COD/manual workflows, and that the order-level callback is not emitted for a no-op reduction.
- The asynchronous sale queue and return queue can still race. A sale enqueued before a later restore may be processed after the restore; durable per-line/cycle ordering is still required.
- The POS mirror adapter uses its own controlled order creation and intentionally does not depend on this hook.

This is a safer trigger boundary, not a completed stock-cycle implementation or production sign-off.

### Stock-cycle implementation contract

The detailed persistence/state-machine contract is maintained in [WC-STOCK-CYCLE-IDENTITY.md](WC-STOCK-CYCLE-IDENTITY.md). It now specifies the target cycle header, immutable per-line mapping snapshot, durable lifecycle event identity, migration/rollout gates, legacy-order handling, and acceptance matrix.

A second idempotency collision is also recorded there: cancellation event keys currently use order ID + item ID + restored quantity. A second legitimate restore of the same quantity for the same item can collide with the earlier event. Both sale and return identities must include the same persisted cycle UUID before repeated-cycle behavior can be enabled.

No cycle schema or cycle-aware processing has been enabled by this documentation change. Existing ledger history must remain untouched, and ambiguous legacy events must fail closed for operator reconciliation.


### Sale ingestion quantity correction

- The order-level reduction hook may include mixed carts where only some lines actually had stock reduced. Sale ingestion now reads each line's `_reduced_stock` metadata and skips lines without an actual reduction, instead of deducting every ordered line quantity.
- Variation-first Junction mapping and fail-closed behavior for unmapped reduced lines remain in place.
- A source smoke guard covers the metadata-based ingestion. Runtime acceptance still needs mixed managed/unmanaged carts and supported WooCommerce stock-management modes.


- Follow-up hardening: both the active sale-ingestion worker and the isolated cycle-capture service now reject non-numeric `_reduced_stock` metadata. Previously malformed non-empty text could cast to zero and be silently treated as a non-stock-managed line. Numeric zero remains a valid no-reduction signal. Source smoke guards added; runtime metadata-corruption tests remain pending.

- Idempotency lookup hardening: `WpStockRepository::hasMovementReference()` now throws on a database read error instead of interpreting a failed lookup as “movement not found”. This prevents an uncertain read from being followed by a duplicate sale/return deduction. Added source smoke guard; database fault-injection test remains pending.
