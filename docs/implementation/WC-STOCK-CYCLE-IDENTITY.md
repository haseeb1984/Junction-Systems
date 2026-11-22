# WooCommerce stock-cycle identity — engineering design

## Problem

Junction currently identifies an online sale movement with the WooCommerce order ID alone (reference_type=wc_order, reference_id=<order id>). That is safe against duplicate lifecycle hooks for one sale, but it cannot distinguish a later genuine stock reduction after WooCommerce has restored the earlier reduction on the same order.

Do not derive a cycle from order status, date_paid, timestamps, or a gateway transaction ID alone. Those values are not a universal, durable stock-reduction identity across COD, manual payment, gateway retries, and order reopening.

## Verified WooCommerce lifecycle signals

The current WooCommerce stock implementation provides stronger signals:

- woocommerce_reduce_order_item_stock fires after WooCommerce actually reduces stock for an individual order item. The item has _reduced_stock metadata saved before the hook. The hook is documented as available since WooCommerce 7.6.0.
- woocommerce_reduce_order_stock fires after the order's item-reduction loop.
- woocommerce_restore_order_item_stock fires after a particular item is actually restored; WooCommerce deletes that item's _reduced_stock metadata before firing it. Available since WooCommerce 9.1.0.
- woocommerce_restore_order_stock fires after the order-level restore loop.
- Refund restocking is a separate operation. WooCommerce tracks _restock_refunded_items and updates _reduced_stock; a payment refund without explicit restock is not a stock return.

Upstream references:
- https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-stock-functions.html
- https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/wc-stock-functions.php
- https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-order-functions.html

## Preferred implementation direction

Use the actual WooCommerce stock-reduction lifecycle, not the generic payment/status hooks, as the authoritative signal that a new stock-reduction cycle occurred.

A candidate design is:

1. Observe woocommerce_reduce_order_stock after WooCommerce has processed all line items. Confirm hook ordering against supported WooCommerce versions before implementation.
2. Create and persist a Junction cycle UUID on the WooCommerce order only when WooCommerce has actually reduced at least one mapped, stock-managed item in that cycle.
3. Snapshot the order's line items and their _reduced_stock quantities, mapping variations before parent products. Persist a durable order-cycle event before acknowledging it.
4. Bind every Junction sale movement to both the WooCommerce order ID and the persisted cycle UUID. Duplicate hooks for the same cycle must resolve to the same event and movement references.
5. Track actual cancellation restores and explicit refund restocks against that same cycle. A later actual stock-reduction event after a prior full restore must receive a new cycle UUID.
6. Keep stock ledger writes transactional for the full cycle where practical. If a cycle is split across per-item jobs, document and test partial application and recovery semantics.
7. Add periodic reconciliation that compares WooCommerce's current stock-reduction metadata and retained cycle events with Junction ledger references. It must detect missed callbacks without inventing a return from payment-refund status.

The exact point at which an active cycle is closed and a new cycle is opened needs careful handling for partial refunds, full refunds, cancellation, reopened orders, and orders whose line items are edited. Do not implement a simplistic order-meta reset until these cases are modeled.

## Required acceptance tests

- First WooCommerce stock reduction creates exactly one Junction sale cycle.
- Duplicate woocommerce_reduce_order_stock callbacks do not create a second cycle.
- Cancellation restores the matching cycle once and closes it.
- Reopening and genuinely reducing stock again creates a new cycle for the same order.
- A delayed callback from the earlier cycle cannot mutate the newer cycle.
- A payment refund without explicit restock does not restore Junction stock.
- Partial and full explicit refund restocks restore only the quantities WooCommerce actually restored, bound to the correct cycle.
- Same-product duplicate order lines remain correctly attributable.
- Variable products use explicit variation mapping before parent fallback.
- Failure to persist the event is surfaced and later found by reconciliation.
- Worker crash, lease expiry, duplicate callbacks, concurrent workers, and POS activity cannot double-apply or lose a cycle.

## Current status

This is a design/research note, not an implementation. The existing order-level idempotency behavior remains in place and repeated payment/reduction cycles remain a production sign-off blocker. Runtime tests and supported-version compatibility checks are required before replacing the current ingestion trigger.
