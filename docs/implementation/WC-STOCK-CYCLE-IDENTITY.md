# WooCommerce stock-cycle identity

## Problem

An order ID alone is not a sufficient idempotency key for WooCommerce stock. The same order can have a real stock reduction, a cancellation/refund restock, and a later real reduction. Each actual reduction pass must have its own immutable cycle identity.

Do not infer cycles from order status, payment dates, timestamps, or gateway transaction IDs.

## Verified WooCommerce lifecycle signals

- `woocommerce_reduce_order_stock` fires after WooCommerce's item-reduction loop. Each reduced line has `_reduced_stock`.
- `woocommerce_restore_order_item_stock` fires after an item is actually restored; WooCommerce removes its `_reduced_stock` metadata before the hook.
- Explicit refund restocking is separate and is represented by `_restock_refunded_items`. A payment refund without the explicit restock hook is not an inventory return.
- POS mirror orders are excluded from online cycle capture.

Upstream references:
- https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-stock-functions.html
- https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/wc-stock-functions.php
- https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-order-functions.html

## Persisted model

The implementation uses three InnoDB tables:

- `pkstore_wc_stock_cycles`: order ID, UUID, per-order cycle number, state, reduction event key and lifecycle timestamps.
- `pkstore_wc_stock_cycle_items`: immutable order-item/product/variation mapping, actual reduced quantity, refund cumulative baseline, allocation snapshot, and separate cancellation/refund returned counters.
- `pkstore_wc_stock_cycle_events`: unique event identity, cycle/order/item, event kind, positive quantity delta, source hook, processing status, attempts, lease token and sanitized error.

Cycle/item/event identity is protected by unique indexes verified by the migration runner. Cycle header states currently used are `reducing`, `active`, `restoring`, and `closed`.

Additional durable queue identity:
- Sale queue migration 2.0.13 stores one queue row per (WooCommerce order, cycle UUID); legacy rows use a fixed zero UUID sentinel.
- Return queue migration 2.0.11 stores the cycle UUID on each cancellation/refund event.
- Both workers recover pending cycle-event rows that were durably captured but whose queue-row enqueue failed, so a transient failure between capture and queue insertion does not silently lose a sale or return.
- Migration 2.0.12 adds the per-cycle refund cumulative baseline.

## Live processing now connected

1. `InventoryHooks::onOrderStockReduced` listens to the actual order-level reduction hook, persists each positive `_reduced_stock` quantity first, then captures a cycle UUID and enqueues a cycle-specific sale job.
2. Capture resolves variation mapping before parent mapping, records the WooCommerce refund cumulative baseline, snapshots the order-item mapping, and persists a reduction event under an order-scoped advisory lock.
3. The sale queue worker binds retried captures to their cycle-specific queue row before processing. The cycle-aware sale worker allocates the captured quantity across active Junction locations, preferring the online location, locks stock rows in deterministic order, writes `wc_stock_cycle` ledger movements, saves per-cycle item metadata and allocation snapshots, and marks the reduction event processed transactionally.
4. Cancellation and explicit refund hooks capture a cycle-bound return event before queueing. The durable return worker retries hook-time capture if it failed transiently, then applies only the actual return delta to the exact saved allocation locations.
5. Refund cumulative values are converted to cycle-local quantities using the baseline captured at reduction time. This prevents a prior cycle's refund total from being applied again to a later cycle on the same order.
6. A cycle closes only when every reduced item quantity has been fully restored by cancellation/refund movements. A new actual reduction after a prior cycle enters restoration receives a new UUID and a separate sale queue row.
7. Duplicate sale queue generations for an already-processed restoring/closed cycle are acknowledged without another deduction. Return events require a durable hook-captured cycle event before ledger mutation.

## Fail-closed boundaries and remaining limitations

- Cancellation return hooks are bound to the current cycle UUID and may be queued while that same cycle is restoring; no later cycle is opened until the current cycle closes. Refund hooks are different: WooCommerce exposes an order-item cumulative restock count, not a cycle identifier. If multiple cycles for the same order remain open, a new refund hook is retained for operator review rather than assigned to the newest cycle. A new sale is blocked while any previous cycle remains in `restoring`, including cancellation cycles whose return worker has not yet committed. This prevents a later sale from allocating against a stale multi-location ledger.
- If cycle capture or return capture cannot be persisted, the order/return is queued for retry. A failed return capture sets a per-cycle order-meta guard so a subsequent reduction cannot silently reuse the old active cycle. The worker retries capture; exhausted events require operator review.
- Legacy rows without cycle identity continue through the legacy order-level path. The implementation does not fabricate historical UUIDs. A cycle-aware sale detects conflicting legacy movements and fails closed.
- Cycle event rows are retained and appear in Sync Health alongside sale/return/stock queues. Authorized operators can retry the linked sale/return queue row with a reason and audit record; the worker then re-drives the cycle event. Direct cycle-event mutation/force-complete controls are intentionally unavailable. Periodic comparison against WooCommerce metadata and proactive alerting are not yet implemented.
- No runtime acceptance has yet been run against a real WooCommerce database, HPOS, supported WooCommerce versions, concurrent workers, or crash recovery. PHP lint and the dependency-free smoke suite are still pending consolidated execution.
- Ambiguous multi-cycle refund events, edited/deleted order items, changed mappings, inactive locations, missing cycle snapshots, and over-returns must remain operator-review cases rather than being silently reassigned.
- The schema migration is protected by the existing pre-migration backup confirmation gate for upgrades. Host-level backup confirmation is an external hosting integration and has not been verified by this repository audit.
- WooCommerce lifecycle hooks and storefront purchasing remain paused until database version 2.0.13 is reached. This avoids accepting online orders while the cycle-aware shared POS/store ledger schema is unavailable.

## Required runtime acceptance

- First reduction creates one cycle and immutable line snapshots; duplicate hooks reuse the same cycle.
- Cancellation before sale-worker execution still processes the original sale then its cycle-bound return.
- Cancellation followed by a genuine re-reduction creates a new UUID and a separate queue row.
- A delayed return for an earlier cycle cannot mutate a later cycle.
- Payment refund without explicit stock restock never returns Junction stock.
- Multiple partial refund restocks apply only each new cycle-local delta and never exceed reduced quantity.
- Mixed cancellation/refund returns close the cycle only when all reduced quantities are restored.
- Same-product duplicate order lines remain attributable by WooCommerce order-item ID.
- Variation mapping is used before parent fallback; historical returns use the captured mapping.
- Worker crash, stale lease, duplicate hook, concurrent workers, capture failure, ambiguous multiple-open-cycle returns, and legacy rows fail safely.
- Fresh install and upgrade migrations succeed on supported MySQL/MariaDB versions after backup confirmation.
- POS mirror orders never enter online cycle ingestion; POS returns restore the original POS location.

## Current status

Cycle-aware capture, sale processing, cancellation/refund binding, per-cycle queue identity, return-baseline handling, and cycle closure are now wired in the implementation branch. This is a source-level implementation, **not a runtime-verified release**. The remaining hard blockers are consolidated PHP/smoke/runtime acceptance, dedicated cycle-event retry/reconciliation controls, periodic comparison against WooCommerce metadata, proactive alerting, and safe operator resolution of ambiguous refund hooks when multiple cycles for one order remain open. Do not treat repeated-cycle behavior as production-certified until the required runtime acceptance passes.
