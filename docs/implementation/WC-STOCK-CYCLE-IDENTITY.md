# WooCommerce stock-cycle identity — engineering design

## Problem

Junction currently identifies an online sale movement with the WooCommerce order ID alone (\`reference_type=wc_order\`, \`reference_id=<order id>\`). That protects one stock reduction from duplicate hooks, but cannot distinguish a later genuine stock reduction after WooCommerce restored the earlier reduction on the same order.

Do not derive a cycle from order status, \`date_paid\`, timestamps, or gateway transaction IDs. None is a universal stock-reduction identity across COD, manual payment, gateway retries, and order reopening.

There is a related return-event collision: cancellation event keys currently use order ID, order-item ID, and restored quantity. A later legitimate restore of the same quantity for the same item can collide with the earlier event. Sale and return idempotency must both become cycle-aware.

## Verified WooCommerce lifecycle signals

- \`woocommerce_reduce_order_item_stock\` fires after WooCommerce actually reduces stock for an individual order item. The item has \`_reduced_stock\` metadata saved before the hook. Available since WooCommerce 7.6.0.
- \`woocommerce_reduce_order_stock\` fires after the order's item-reduction loop.
- \`woocommerce_restore_order_item_stock\` fires after an item is actually restored; WooCommerce deletes that item's \`_reduced_stock\` metadata before firing it. Available since WooCommerce 9.1.0.
- \`woocommerce_restore_order_stock\` fires after the order-level restore loop.
- Refund restocking is separate. WooCommerce tracks \`_restock_refunded_items\` and updates \`_reduced_stock\`; a payment refund without explicit restock is not a stock return.

Upstream references:
- https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-stock-functions.html
- https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/wc-stock-functions.php
- https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-order-functions.html

## Required persistence model

The implementation must use durable database records as the source of truth. WooCommerce order metadata may carry a convenience pointer, but must not be the only record of cycle identity.

### Cycle header

A dedicated \`pkstore_wc_stock_cycles\` table should contain:

- primary key;
- WooCommerce order ID;
- immutable cycle UUID (unique);
- monotonically increasing cycle number per order (unique with order ID);
- state: \`reducing\`, \`active\`, \`partially_returned\`, \`closed\`, or \`needs_review\`;
- immutable reduction event key;
- created, activated, closed, and updated timestamps.

A unique key on (order ID, cycle number) and a unique key on cycle UUID are required. The current/active cycle must be selected under a database transaction and an order-scoped lock. Do not rely on a non-atomic read-then-write of order metadata.

### Per-line cycle snapshot

A \`pkstore_wc_stock_cycle_items\` table should contain:

- cycle UUID and WooCommerce order-item ID;
- WooCommerce parent product ID and variation ID;
- resolved Junction inventory product ID and inventory location ID;
- quantity actually reduced by WooCommerce for this cycle;
- cumulative cancellation-restored quantity;
- cumulative explicit-refund-restocked quantity;
- created and updated timestamps.

Unique key: (cycle UUID, WooCommerce order-item ID). Store the resolved Junction product/location mapping at reduction time; do not re-resolve historical returns against a mapping that an operator may later change.

### Durable lifecycle events

A \`pkstore_wc_stock_cycle_events\` table should retain immutable event identity and processing state for reduction, cancellation restore, and explicit refund restock events:

- unique event UUID / event key;
- cycle UUID;
- order ID and, where applicable, order-item ID;
- event kind and positive quantity delta;
- source hook and source metadata needed to audit the event;
- status, attempts, next-attempt time, lease timestamp/token, sanitized last error;
- created and processed timestamps.

A callback must persist its event before returning. If persistence fails, log a critical error and leave a detectable reconciliation gap; never report it as accepted. Workers must use claim-token fencing, bounded retries, and a visible \`needs_review\` state. Event rows are retained after completion so a later cycle cannot reuse an earlier idempotency key.

The initial durable storage contract is now installed by migration 2.0.9: cycle headers, immutable per-line mapping snapshots, and retained lifecycle-event rows with claim-token fields. The migration verifies required columns and unique indexes after dbDelta, as well as table presence. The schema is now paired with a registered `CaptureWooCommerceStockCycleUseCase` implementation. It takes an order-scoped MySQL advisory lock, reads only actual `_reduced_stock` quantities, resolves and snapshots the Junction product/location mapping, and transactionally writes a cycle header, line snapshots, and reduction event. It is deliberately not called by the WooCommerce hook yet: the existing sale/return workers still use order/item-level references, and no cycle-close/return binding worker exists. This staged service therefore does not make repeated-cycle behavior safe or alter live stock behavior. Before wiring it into hooks, implement cycle-aware workers and return capture, plus runtime verification of the migration and lifecycle.

## Lifecycle and state transitions

1. **Reduction begins.** Listen to the actual order-level reduction hook after WooCommerce's line-item loop. Under an order-scoped lock, inspect each line's persisted \`_reduced_stock\`. Ignore zero/missing reductions. Resolve variation mapping before parent fallback and snapshot only the quantities WooCommerce actually reduced.
2. **Cycle creation.** If this is a new reduction pass, create a new UUID and cycle number and persist the immutable line snapshot plus a reduction event atomically. Duplicate callbacks for the same still-active reduction must resolve to the same cycle. A later reduction after the prior cycle is fully closed must create a new cycle.
3. **Sale application.** Process the reduction event from its snapshot, not by re-reading mutable order lines. Ledger reference identity must include the cycle UUID (and line identity where movements are per-line). Commit the cycle's inventory movements and event completion consistently; a partially applied cycle must resume without duplicating committed movements.
4. **Cancellation restore.** The per-item restore callback runs after WooCommerce removes \`_reduced_stock\`. Resolve the still-open cycle and its saved line snapshot under the same order lock, persist the return event with that cycle UUID and item ID, then let the worker apply only the actual positive stock delta. When all reduced quantities in that cycle have been returned, close it.
5. **Explicit refund restock.** Capture the matching order-item identity and WooCommerce cumulative \`_restock_refunded_items\` value at hook time. Persist a cycle-bound event. Apply only the new actual restock delta, never the total refunded payment amount. A payment refund without the explicit restock hook creates no inventory return.
6. **New reduction after restore.** Once a cycle is fully returned and closed, a subsequent actual reduction pass receives a new UUID, even though the WooCommerce order and line-item IDs are unchanged.
7. **Reconciliation.** A periodic read-only detector compares WooCommerce reduction/restock metadata, cycle snapshots, and ledger references. It reports missing, ambiguous, or partially applied events for operator review. It must not invent a return from order status or payment-refund state.

## Concurrency and edge cases that must fail closed

- A cycle's reduction snapshot and its event must be committed atomically before a worker can process it.
- A restore callback must bind to the cycle active when WooCommerce performed that restore. A delayed worker must never infer the cycle from whichever cycle happens to be active later.
- Same-product duplicate order lines must retain distinct order-item identities; never aggregate away the identity needed to bind returns.
- Partial refunds, multiple explicit restocks, partial cancellation, and mixed cancellation/refund returns must be bounded by the original reduced quantity for each line.
- A refund hook without an unambiguous order-item identity must remain in \`needs_review\`; do not guess based on product ID alone.
- Edited/deleted order items, changed product mappings, inactive locations, missing cycle snapshots, or returns exceeding the outstanding reduced quantity must be visible reconciliation cases, not silently reassigned.
- POS mirror orders remain excluded from online sale ingestion. POS returns continue to use the immutable POS product/location/order reference and must not be attached to an online WooCommerce cycle.
- Order-scoped locks must cover cycle selection and event persistence. Database transactions must use InnoDB tables. Worker lease fencing protects queue ownership but does not replace ledger-level idempotency.
- Legacy orders that already have order-level movements cannot safely be assigned a fabricated historical cycle. Preserve their old references; new cycle-aware processing must detect and quarantine ambiguous legacy returns rather than double-credit stock.

## Migration and rollout

1. Add cycle tables and indexes in a new migration; do not rewrite existing stock movement references.
2. Keep current order-level ingestion available only as an explicitly documented legacy path until the cycle-aware worker is ready. Do not run both paths for the same order.
3. Introduce cycle-aware capture and worker processing behind a centrally controlled feature gate, default OFF until acceptance tests pass on supported WooCommerce versions and HPOS.
4. Backfill no historical cycle UUIDs. Existing orders with unresolved reductions/restocks remain legacy and are reconciled manually.
5. Enable for a controlled test store, compare cycle snapshots against WooCommerce stock metadata and Junction ledger, then enable more broadly only after runtime sign-off.
6. Rollback must stop new cycle capture without deleting cycle/event/ledger history. It must never automatically reverse stock movements.

## Required acceptance tests

- First WooCommerce stock reduction creates exactly one cycle and immutable per-line snapshots.
- Duplicate reduction callbacks map to the same cycle.
- Cancellation restores the matching cycle once and closes it only when all line quantities are returned.
- Reopening and genuinely reducing stock again creates a new UUID for the same order and item IDs.
- A delayed callback from an earlier cycle cannot mutate a newer cycle.
- A payment refund without explicit restock does not restore Junction stock.
- Multiple partial refund restocks apply only each actual delta and never exceed the original reduced quantity.
- Same-product duplicate order lines remain correctly attributable.
- Variable products use explicit variation mapping before parent fallback; historical return uses the mapping snapshot.
- Cancellation and refund returns in the same cycle cannot over-credit the line.
- Failure to persist a callback is surfaced and detected by reconciliation.
- Worker crash, lease expiry, duplicate callbacks, concurrent workers, changed mappings, legacy orders, and POS activity cannot double-apply or lose a cycle.
- Migration works on both fresh installs and existing installs after the required backup gate.

## Current status

The cycle schema scaffolding is implemented in migration 2.0.9, but this document remains the operational design contract rather than evidence of a working cycle-aware path. Current sale and return idempotency remain order/item-level; the new tables are not yet connected to hooks or workers and do not make repeated-cycle behavior safe. Do not enable repeated-cycle production behavior until atomic capture, cycle-bound sale/return workers, reconciliation, supported-version compatibility, schema-index verification, and runtime acceptance tests are complete.
