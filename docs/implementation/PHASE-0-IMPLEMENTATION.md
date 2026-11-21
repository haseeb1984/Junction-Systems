# Phase 0 implementation — status and operational gates

Branch: `implementation/phase-0-foundation`  
Base: `main` at `38b6eee00d92b5a7240c62fb3e8d06a3f80c3c4b`

This is an implementation branch only. No changes have been merged to `main`, and no production deployment has been performed.

## Implemented in this branch

- Added a PSR-4-compatible exception class file for each license exception. The previous single file declared several classes that the file-based autoloader could not resolve by their individual class names.
- Fixed the license feature guard to use the container property actually declared by `ThemeBootstrapper`.
- Connected the setup wizard to the existing license UI and added a license tab, so license activation/status is reachable from the WordPress admin.
- Made unknown license tiers return no tier-derived entitlements.
- Added a central-entitlement intersection to `FeatureManager`: a local feature toggle can turn a feature off, but cannot grant a feature that the central license does not include.
- Gated payment gateway registration by central entitlement and class availability. EasyPaisa remains unavailable because its gateway class is absent from this repository. Foree is deliberately not registered until its callback is changed to provider-side payment verification; its existing browser-return callback is not safe to authorize payment.
- Validate license API HTTP status and entitlement response shape. Definitively invalid licenses no longer receive the offline grace period. Local deactivation is retained unless the central service confirms deactivation.
- Use authenticated AES-256-GCM for newly stored integration secrets and license cache/key values, while retaining legacy AES-CBC read support for migration. The bundled Groq plugin now encrypts its API key and migrates existing plaintext keys when the assistant next uses them.
- Removed duplicate theme AI chat controller registration. The bundled assistant now checks the central AI entitlement before it initializes.
- Made branding exports omit the client license key. Branding imports accept only allowlisted presentation fields and cannot import feature entitlements or license credentials.
- Restricted POS REST routes to WooCommerce managers and added cashier/session ownership checks for session opening, closing, current session, sale processing, and order-history access. Sale failures no longer expose exception messages to API clients.
- Added a lock-protected, versioned migration runner with required-table verification. Upgrade migrations are held unless a hosting integration confirms a recent full backup through the `pkstore_migration_backup_confirmed` filter.
- Added a GitHub Actions workflow for PHP lint, dependency-free smoke tests, and JavaScript syntax checks.

## Additional source QA fixes — 21 November 2026

A second source review found and corrected these issues before considering the branch ready:

- Offline license grace now re-checks the cached license expiry and bound domain. Definitive invalid, revoked, expired, and domain-mismatch responses clear stale cached entitlements so later feature checks in the same PHP request cannot reuse them.
- HTTP 401 and 410 license responses are treated as definitive invalid/revoked states rather than service outages eligible for grace.
- Existing, unversioned installations are now detected by their existing PK Store tables. They cannot be mistaken for fresh installs to bypass the pre-migration backup confirmation gate.
- Legacy plaintext integration secrets are migrated to authenticated AES-256-GCM storage on read, and the plaintext option is deleted only after the encrypted value is saved. A present but unauthentic encrypted value fails closed rather than falling back to plaintext.
- Package smoke coverage now checks Basic, Standard, and Enterprise entitlements and prevents higher-tier extras leaking into lower tiers.

## Validation status

- Source changes are committed on this branch.
- GitHub Actions workflow has been added and runs are being created, but each job currently fails before executing any step (GitHub reports runner ID 0, no runner name, and an empty step list). Therefore PHP lint and smoke tests are **not yet confirmed passing**; repository Actions/runner availability must be fixed or a runner made available.
- No WordPress/WooCommerce runtime test was possible from the repository connector.
- No production credentials, payment-provider credentials, or hosting account credentials were accessed.
- No merge or deployment was performed.

## External prerequisites still required before Phase 0 can be declared production-ready

### Central licensing service

The licensing API implementation is not present in this repository. The client currently relies on the external `license.junctionsystems.pk` service. The API's response-signing contract, package entitlement payload, payment lifecycle and downgrade propagation must be verified against that service. The client validates HTTPS response status and schema, but cannot implement response HMAC verification without the server's documented signing contract and matching server-side change.

### Hosting backups and restore

Junction owns automated hosting backups; target RPO is 24 hours. Customer self-service export remains optional and is not a substitute for Junction's backup responsibility. No hosting account connection or provider backup/restore API was available in this implementation session, so backup schedules, retention, restore tests, and actual RPO cannot be certified here.

The migration runner intentionally blocks future upgrades until the hosting layer supplies a truthful `pkstore_migration_backup_confirmed` filter result. That integration must only return true after a recent full database and website-files backup has completed and is verifiable.

### Payment providers

- JazzCash callbacks now verify the signed transaction reference, expected amount, merchant, currency and success code against the initiated order.
- EasyPaisa cannot be activated from this repository because the referenced gateway class is missing.
- Foree is intentionally withheld from gateway registration pending the provider's documented server-to-server verification endpoint and response-signature contract. A browser query-string `SUCCESS` is not proof of payment.

### Runtime and recovery acceptance

Before production rollout, run the workflow successfully, install this branch on a staging WordPress + WooCommerce site, exercise license activation/expiry/offline/revocation, test JazzCash sandbox callbacks and replay cases, test POS cashier isolation, verify secret migration from legacy options, and perform a full restore from the hosting backup. Record the measured RPO and RTO. Do not deploy until these checks pass.

## Explicitly out of scope for this Phase 0 branch

Remaining POS/inventory redesign beyond the specific transaction, concurrency, idempotency and stock-sync fixes documented below; FBR integration, central billing portal, Groq multi-key pool, courier remittance reconciliation, full support ticketing/human handoff, and marketing/ERP integrations remain in their own implementation phases. This branch does not claim those features are complete.

## Additional source QA — 21 November 2026 (continued)

- Corrected the bundled Groq assistant's legacy-key migration path: it now confirms the encrypted option was persisted before use, decrypts only the stored ciphertext for the outbound request, and refreshes the settings array after migration so the same request does not reuse a stale plaintext option.
- The latest workflow run for commit `247b85960d315d43d96af969a237f48c1b885476` (run `44866110191`) still terminates in approximately two seconds with `runner_id: 0`, no runner name, and an empty `steps` array. This confirms the job never reached checkout or PHP/Node execution. The repository connector cannot inspect or change the account's Actions/billing/runner policy settings, so the exact account-side cause remains unverified.
- No code-level test result is being represented as passing. The workflow must execute on a runner before PHP lint, smoke tests, and JavaScript syntax checks can be accepted.

### Continued Phase 0 corrections — 21 November 2026

- Groq assistant: legacy plaintext key migration now verifies encrypted persistence, returns only the decrypted ciphertext value for the current request, and refreshes settings after migration so a stale plaintext options array is not reused.
- POS queue: removed the false-success behavior that marked records as WooCommerce-synced without creating WooCommerce orders. Pending records now remain pending and the REST endpoint reports HTTP 501 until a real WooCommerce order adapter exists.
- POS sale: server-side inventory product price is now authoritative; browser-supplied unit prices are ignored. Product IDs and quantities are validated, quantities must be positive integers, inactive/missing products are rejected, discounts must be non-negative and cannot exceed subtotal, and the session location must match the sale location.
- These are source-level corrections only. They have not been runtime-tested because the GitHub Actions job is still failing before runner allocation. Do not treat Phase 0 as production-ready until the workflow executes and staging tests pass.

- POS session persistence: closing cash, cash/JazzCash/EasyPaisa totals, status, and close timestamp are now written back to the session row. Added entity getters required by the repository and rejected negative opening/closing cash, negative sale totals, and repeated close transitions.
- POS tender accounting: card totals are now tracked separately from cash, and the session repository persists/hydrates card totals along with cash, JazzCash, and EasyPaisa totals. POS payment methods are restricted to the methods currently exposed by the register; unsupported split-tender payloads are rejected instead of being silently counted as cash. Invalid opening/closing cash is rejected at the REST boundary.


- POS session entity: tender validation now happens before aggregate sales totals are mutated. An unsupported tender therefore cannot increment total sales before throwing.
- POS session repository: failed database inserts and failed updates now raise explicit persistence exceptions instead of returning a session ID or reporting success after a failed write.
- Remaining POS transaction risk: order creation, line-item persistence, stock movements, and session totals are not yet one database transaction. The offline replay path also still needs a durable idempotency key before retry-after-timeout can be considered safe. These are recorded as open implementation work, not claimed fixed.


### POS transaction integrity corrections — 21 November 2026

- POS sale writes now run inside a database transaction covering the POS order, line items, stock quantity/movement updates, and session tender totals. Stock shortages and persistence exceptions abort the sale and trigger rollback; the previous behavior that logged a stock shortage but still completed the sale has been removed.
- The POS session is reloaded with a row lock inside the transaction before tender totals are changed, preventing concurrent sales from overwriting each other's session aggregates.
- Inventory stock rows are selected with `FOR UPDATE` during transactional adjustments, serializing competing deductions for an existing product/location stock row.
- Stock-level initialization, quantity writes, movement-ledger inserts, POS order inserts, and POS line-item inserts now throw on database persistence failures so the outer sale transaction can roll back.
- This depends on the relevant WordPress custom tables using InnoDB, as declared by the inventory/POS schema installer. Runtime verification remains outstanding.
- Durable client-supplied idempotency keys and offline replay de-duplication are still not implemented. A lost HTTP response followed by a retry can still create a second sale; this remains an explicit blocker for claiming offline retry safety.

### POS stock synchronization boundary (QA correction)
- POS stock-level and movement writes remain inside the POS database transaction.
- WooCommerce product stock synchronization is now deferred until after the POS transaction commits, avoiding WooCommerce hooks/product saves running before the POS sale is durable.
- If post-commit WooCommerce synchronization throws, the sale remains committed and the error is logged with the order reference and product ID. This is safer than rolling back the local sale after external side effects, but it is not a durable retry queue; reconciliation/retry handling remains required before production.
- This change does not solve offline-sale idempotency, concurrent stock reservation for absent stock rows, or proof that every participating legacy table is InnoDB.

### Inventory concurrency and POS receipt trust-boundary QA
- Stock row initialization now uses `INSERT IGNORE` against the unique `(product_id, location_id)` key before `SELECT ... FOR UPDATE`. This closes the first-write race where two transactions could both observe a missing row and attempt competing inserts.
- POS WhatsApp receipt links now resolve the order by its server-side order reference, verify the caller owns the associated cashier session (or is an administrator), and use the persisted server-side total. Browser-supplied line items and totals are ignored.
- The WhatsApp endpoint only constructs a `wa.me` link; it does not send a message itself. Phone format is validated before constructing the link.
- Remaining gaps: offline-sale idempotency, durable retries for post-commit WooCommerce stock synchronization, and runtime verification of transaction/table-engine behavior.

### POS offline retry idempotency
- The POS client now creates a cryptographically random request reference (`P-` plus 26 lowercase hexadecimal characters) before submitting a sale and persists that same reference with the IndexedDB offline queue item.
- The existing POS `order_ref` column already has a unique index. The client reference is used as the order reference, so a replay returns the existing committed order instead of deducting stock and recording tender totals again.
- Concurrent retries are protected by the unique order-reference index; after a failed insert/transaction rollback, the use case checks for the winning committed order and returns it when it belongs to the same session/location.
- REST retries can retrieve an existing order even after the cashier has closed the session, subject to cashier/session ownership checks.
- Offline queueing is now limited to transport/response failures. HTTP validation and authorization errors are surfaced and are not silently queued as offline sales.
- This is request-level idempotency, not a complete offline authorization model: an offline sale is not guaranteed until the server accepts it, and queue durability depends on the browser's IndexedDB storage. A payload fingerprint/reuse-mismatch check and full runtime retry tests remain recommended.

### POS session concurrency hardening
- Opening a POS session now starts a database transaction and locks the active inventory-location row before checking for an existing open session and inserting a new one. Concurrent open requests for the same location therefore serialize, including the first-session/no-existing-row case.
- Closing a POS session now runs in a transaction and locks the session row through the repository's `SELECT ... FOR UPDATE` before checking status, applying closing cash and persisting the closed state. This serializes close against sale processing, which also locks/rechecks the session inside its sale transaction.
- These protections rely on the location and POS session tables using InnoDB; the installer declares both as InnoDB. They still require runtime concurrency tests on the target MySQL/MariaDB version before production sign-off.

### Durable WooCommerce stock synchronization recovery — 21 November 2026

- Added the InnoDB `pkstore_wc_stock_sync` outbox table and a versioned `2.0.1` migration. Existing installations still pass through the migration runner's pre-upgrade backup confirmation gate.
- Inventory stock adjustments now persist a product-level sync intent alongside the stock/movement writes. For POS sales, this write is inside the same transaction as the sale, so rollback also removes the queued intent.
- Queue entries are coalesced by inventory product and carry a unique generation token. The worker recalculates the current stock snapshot instead of replaying old quantity deltas; a newer stock change cannot be erased by completion of an older in-flight job.
- All WooCommerce stock snapshot writers now use a per-product MySQL advisory lock. A newer queue generation remains serialized behind an in-flight job and is released for a fresh snapshot afterward; hourly full reconciliation uses the same lock and persists retry intents.
- Added a five-minute WordPress cron worker, atomic row claiming, recovery of jobs left processing for more than ten minutes, and exponential retry delays capped at six hours. Retries continue rather than silently discarding a job. Logs store exception classes, not raw provider error text.
- Immediate WooCommerce sync remains best-effort. If it fails, the persisted queue remains available for retry. Successful immediate sync may be repeated once by the worker; this is intentional and idempotent because the worker writes the current stock snapshot.
- WordPress cron is traffic-driven unless the host configures a real system cron to invoke WP-Cron. A production deployment should configure and monitor a real cron trigger; otherwise retry timing is not guaranteed during low-traffic periods.

- Queue coalescing now preserves an in-flight claim using the pre-update `claimed_at` state; a concurrent stock change updates the generation without accidentally clearing the worker's claim or resetting its retry state.
- A queued product with a missing inventory record, missing WooCommerce link, or deleted/unavailable linked WooCommerce product now fails explicitly and remains retryable instead of being reported as successfully synchronized and silently removed. Such records require the product mapping/product to be restored or an operator-led reconciliation; automated retry alone cannot repair missing catalogue links.
- Confirmed inventory policy: Junction's Online Store and POS share one integrated inventory. Each location retains its own stock ledger, while the single WooCommerce product stock quantity is the combined stock across all locations. This is intentional and confirmed by the product owner; do not change it to online-location-only stock. POS sales, online orders, receipts and adjustments must continue to affect the same shared inventory model.
- Remaining validation: run PHP lint and smoke tests, test migration against a backed-up existing database, force WooCommerce save failures and verify retry/backoff/recovery, test concurrent stock changes during an in-flight sync, and verify the host's real cron execution. Manual absolute stock counts now wrap stock-level, movement-ledger and queue writes in one transaction; runtime verification is still outstanding.
