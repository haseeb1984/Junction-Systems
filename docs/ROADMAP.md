# Juncsys / Junction Systems — Roadmap

> This roadmap covers improvements beyond the currently documented POS, inventory, ecommerce, Dukaan AI and integration capabilities.

## Reliability and security
- Define a dedicated POS capability/role matrix for cashier and financial operations.
- Make sale and stock movement consistency explicit, including the intended overselling/reservation behavior.
- Add regression tests for POS totals, split payments, receipts, offline synchronization and inventory invariants.
- Add idempotency and reconciliation tests for payment and courier callbacks.

## Dukaan AI
- Publish a supported provider/model matrix and correct any provider/model routing mismatch.
- Add tests for Urdu/English responses, product grounding, streaming failures and prompt-injection resistance.

## Operations and integrations
- Document WordPress/WooCommerce/PHP compatibility and plugin/theme installation.
- Document provider credentials, callback setup, operational monitoring and failure recovery.
- Add backup/restore and rollback runbooks.
- Verify marketing-provider scopes and sync/retry behavior.

## Release quality
- Establish automated checks for the supported runtime.
- Align theme metadata and internal version constants.
- Keep feature documentation synchronized with source changes.
