# WooCommerce stock lifecycle integration

## Trigger contract

Online sales are enqueued from `woocommerce_reduce_order_stock`, after WooCommerce's actual item stock-reduction pass. Generic payment-complete or processing/completed status changes are not treated as proof of stock reduction.

- Each line is ingested only when it has a positive, numeric `_reduced_stock` value.
- The hook persists the per-line reduction snapshot before asynchronous processing because WooCommerce removes `_reduced_stock` when stock is restored.
- Variation mapping is preferred; parent-product mapping is used only when no explicit variation mapping exists.
- Junction POS mirror orders are excluded because the POS ledger already deducted stock.
- Cancellation restore and explicit refund-restock hooks are separate. A payment refund without an actual WooCommerce restock event does not return Junction inventory.

## Multi-location stock allocation

- Each actually reduced online order line is allocated from the shared active-location inventory pool. The Online Store location is preferred when available; remaining quantity is allocated across other active locations in stable location-ID order.
- Product/location rows are locked in deterministic order. The sale fails closed if the combined Junction quantity is insufficient.
- The exact allocation is saved per order item and per stock-cycle UUID. The cycle-item table stores the allocation snapshot; order-item metadata retains the cycle-specific return counters and refund baseline.
- Sale ledger movements use `wc_stock_cycle` references. Replays verify the saved allocation and matching movement rows rather than deducting again.
- WooCommerce aggregate stock publication occurs after the Junction ledger transaction commits. Publication failures remain in the durable stock-sync outbox.

## Cancellation and refund returns

- Cancellation returns use `woocommerce_restore_order_item_stock` and are bound to the cycle UUID captured for that reduction.
- Explicit refund restocks use `woocommerce_restock_refunded_item`. The captured `_restock_refunded_items` baseline converts WooCommerce's order-item cumulative count into a cycle-local refund quantity.
- Returns restore the exact original product/location allocation, not the current location mapping or current online-location balance.
- Return events are captured durably before queue processing. The worker retries capture if the hook-time DB operation failed, and the cycle is marked `restoring` while its return is outstanding.
- A cycle closes only after every captured reduction quantity is restored. A later actual reduction receives a new UUID and a separate sale queue row.
- Cancellation hooks are bound to the current cycle UUID and can restore a cycle independently while an earlier cancellation cycle is still closing. Refund hooks expose an order-item cumulative count rather than a cycle ID; if multiple cycles for one order remain open, a new refund event is left for operator review. New sales are blocked while a prior partial-refund cycle remains open without a full cancellation return.

## Durable queues and migrations

- Sale queue migration 2.0.13 adds a cycle UUID and a composite unique key on order + cycle. The fixed zero UUID sentinel is reserved for legacy rows awaiting cycle capture.
- Return queue migration 2.0.11 adds cycle UUID.
- Stock-cycle migration 2.0.9 creates cycle, item and event tables; 2.0.11 adds allocation snapshots; 2.0.12 adds refund cumulative baselines.
- Migration verification checks required columns and unique indexes. Existing-install migrations remain blocked unless the hosting integration confirms a recent pre-migration backup.
- Sale, return and stock-sync workers use persisted claim tokens/generation fencing and bounded retry. Exhausted queue events move to `needs_review`.
- The cycle capture and processing services are registered and connected to the actual WooCommerce hooks and scheduled workers.

## Important limitations

- Legacy sale/return queue rows without cycle identity continue through the legacy order-level path. Historical UUIDs are not fabricated. Conflicting legacy movements fail closed when a new cycle-aware sale is processed.
- If multiple cycles for one order remain open, subsequent return hooks are ambiguous. Those events are retained for review rather than assigned to the newest cycle.
- Cycle events are stored durably, but the current Sync Health UI primarily displays sale, return and stock-sync queues. A dedicated cycle-event reconciliation screen, periodic reconciliation against WooCommerce order-item metadata, and proactive alerting are not yet implemented.
- Runtime acceptance has not yet been run against a real WooCommerce database, HPOS, supported WooCommerce versions, concurrent workers, or crash recovery. Source-level smoke guards are not a substitute for those tests.

## Required runtime acceptance

- Managed/unmanaged mixed carts and products with stock management disabled.
- COD/manual payment, duplicate reduction hooks, and a second genuine reduction after cancellation.
- Cancellation before sale-worker execution; sale must process first, then its return.
- Cancellation followed by re-sale with both sale queue rows and return queue rows pending.
- Multiple locations, split allocation, insufficient combined stock, and stock sync after commit.
- Partial refund restock, cumulative refund baseline across cycles, cancellation after partial refund, and refund-only payment without restock.
- Duplicate return hooks, multiple open cycles, ambiguous return events, changed/deleted mappings, edited order items, and over-returns.
- HPOS, supported WooCommerce versions, stale worker leases, crash recovery, concurrent workers, DB failures and migration upgrade from each supported schema version.
- POS mirror sale/cancellation/refund must never be ingested as an online sale and must restore the original POS location.

## Current status

The source implementation now has cycle-aware capture, sale queue identity, per-cycle allocation/ledger processing, cycle-bound cancellation/refund queues, per-cycle refund baselines, and cycle closure. This is **not production sign-off**: consolidated PHP lint, smoke tests, DB migration tests, and the runtime acceptance matrix remain pending.
