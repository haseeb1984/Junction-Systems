# WooCommerce inventory event reconciliation — requirements

## Purpose

The durable order and return queues make persisted WooCommerce inventory events retryable. They do not resolve events that can never be mapped, callbacks that could not be persisted because WordPress/MySQL was unavailable, or lifecycle states requiring an operator decision.

This is a production requirement, not a claim that the UI currently exists.

## Required event visibility

The Junction store-owner inventory screen must show separate counts and filterable rows for:

- WooCommerce sale events waiting, processing, retrying, or failed.
- Cancellation restock events waiting, processing, retrying, or failed.
- Explicit refund-restock events waiting, processing, retrying, or failed.
- Product stock-sync outbox items waiting, processing, retrying, or failed.

Each row should expose only operationally necessary fields: event kind, WooCommerce order number/link, order-item/product identity, location, quantity, first-seen time, last-attempt time, attempt count, next retry time, and a safe failure category. Do not expose raw exception text, API credentials, customer address, phone, or payment secrets.

## Safe operator actions

1. **Retry now** — schedules the existing immutable event for immediate retry. It must not create a new ledger movement reference.
2. **Resolve mapping** — allows an authorized store owner to link the exact WooCommerce product/variation or order line to an existing Junction inventory product. Show before/after mapping and require confirmation.
3. **Ignore with reason** — only for an event proven not to represent a Junction-managed stock item or a duplicate source event. Require a reason and record operator identity/time. Never silently delete an event.
4. **Reconcile product stock** — queues a fresh aggregate snapshot from Junction's authoritative multi-location ledger to WooCommerce. It must not mutate the Junction ledger.
5. **Escalate** — marks the event for Junction support without changing stock.

No operator action may directly mark a sale or return as applied. A retry must pass through the same idempotent application use case and order lock as automatic processing.

## Actions that must not exist

- A button that blindly adds/subtracts arbitrary quantity to “fix” a WooCommerce event.
- A refund action that restores Junction stock solely because the payment status is refunded.
- A force-complete action that deletes a queue row without an auditable resolution.
- A product mapping change that rewrites historical movement references.
- A way for an employee/cashier role to reconcile or ignore inventory events unless separately authorized.

## Audit record

Every manual action must create an append-only audit entry with actor user ID, timestamp, event ID/key, action, reason, previous state, resulting state, and relevant mapping identifiers. Never store secrets or raw provider payloads in this log.

## Queue state model

Recommended states:

- \`pending\`: eligible for automatic retry at \`next_attempt_at\`.
- \`processing\`: claimed by a worker; stale claims can be recovered.
- \`needs_review\`: automatic retries stopped because the event is ambiguous or requires a human mapping decision.
- \`resolved\`: operator resolved the event through a safe action; preserve the audit record.

A bounded retry policy should distinguish transient infrastructure errors from permanent data errors. Transient failures use capped exponential backoff and alerting. Permanent mapping/ambiguity errors move to \`needs_review\`; they must not retry forever without visibility.

## Re-payment and order lifecycle cycles

The current sale ledger reference is order-level (\`wc_order\` + order ID). That protects duplicate hooks for one sale cycle, but cannot safely distinguish a later new payment/reopening after a cancellation or restock.

Before supporting that lifecycle, implement a persisted payment-cycle identity sourced from a verified WooCommerce payment transition, store it on the sale event and ledger reference, and bind cancellation/refund restock events to the same cycle. Do not derive a cycle from order status alone: status can change without a new payment, and refunds can occur without stock restock.

Acceptance cases must prove:

- First payment deducts once.
- Duplicate payment/status hooks for the same cycle do not deduct twice.
- Cancellation/restock restores only the original cycle once.
- A genuinely new payment cycle deducts again.
- A refund without explicit stock restock does not restore stock.
- Explicit partial/full restocks restore only the restocked quantity once.
- A delayed callback from an earlier cycle cannot restore or deduct stock in a later cycle.

## Operational dependencies

- Configure a real system cron trigger; WP-Cron alone is traffic-driven and does not guarantee retry timing.
- Alert on oldest pending event age, repeated failures, stale claims, queue growth, and callback persistence failures.
- Provide periodic source reconciliation against WooCommerce order/refund records to detect callbacks lost while the site/database was unavailable.
- Test against target WooCommerce and MySQL/MariaDB versions, including HPOS, variations, duplicate product lines, worker crashes, and concurrent POS sales.

## Current implementation boundary

The current branch implements durable order and return queues, idempotent ledger references, order-level locking, capped retry, and stale-claim recovery. It now includes a read-only WooCommerce inventory queue health screen for sale, return, and aggregate stock-sync events, limited to users with `manage_woocommerce` and the inventory entitlement. The screen exposes operational status, sanitized error categories, and all-row counts grouped by queue status; it deliberately has no retry, ignore, force-complete, mapping-edit, or stock-mutation controls. The current workers delete completed rows, so these totals represent retained work, not historical throughput. The controlled operator actions/audit log, `needs_review` workflow, alerting/source reconciliation, and payment-cycle identity are still not implemented. Production sign-off remains blocked on these items and runtime acceptance testing.
