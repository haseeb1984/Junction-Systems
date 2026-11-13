# Juncsys / Junction Systems — Gaps and Verification Register

> Items below are source-review findings and verification tasks, not a statement that the owner-tested product is broken.

## POS and inventory consistency
1. Review the inspected POS permission callback: it accepted `manage_woocommerce` OR `edit_posts`; confirm and narrow financial-operation access to the intended dedicated capabilities/roles.
2. The inspected sale path appeared able to continue when stock deduction fails. Confirm the intended overselling/reservation policy and enforce a consistent transaction outcome.
3. Verify sale totals, split-payment arithmetic, receipt/order history and offline queue replay under duplicate, delayed and interrupted requests.
4. Verify inventory invariants across locations, purchase orders, stock adjustments, POS sales and WooCommerce synchronization.

## Dukaan AI
- Verify each provider/model combination; one inspected service path appeared to pass a Gemini model identifier through an OpenAI provider path.
- Confirm the active path, supported model names, fallback behavior, streaming errors and credential configuration.
- Maintain prompt-injection and product-context boundaries with regression tests.

## Integrations and operations
- Verify payment and courier callbacks for signature validation, idempotency, retries and reconciliation.
- Confirm production merchant/provider credentials separately from source-level integration support.
- Document Mailchimp/Klaviyo authorization scopes, sync behavior and failure handling.
- Confirm installation, configuration, backup/restore and rollback procedures for the WordPress/WooCommerce environment.

## Quality and release management
- No dedicated automated test files were found in the inspected tree; verify whether tests exist elsewhere and add coverage for POS, inventory, checkout, AI and integrations.
- Theme metadata version and internal `PKSTORE_VERSION` appeared inconsistent; confirm the intended release-version source.
- Record supported WordPress, WooCommerce and PHP versions.

## Evidence discipline
Treat these as findings to validate. Do not downgrade working features or claim a defect is confirmed without reproducing it and checking the intended business rule.

## Payment and provider-registration findings (source-reviewed 2026-11-13)

- **Gateway registration mismatch:** `GatewayRegistry::register()` registers `JazzCashGateway::class` and `EasyPaisaGateway::class`, but the tracked `src/` tree contains no `EasyPaisaGateway.php` or other EasyPaisa class definition. Confirm whether the class is supplied by another loaded package; if not, the EasyPaisa registration points to a missing class and must be reconciled before describing it as an implemented payment adapter.
- **ForeePay is not wired into the current gateway registry:** `src/Infrastructure/Payment/Foree/ForeePayGateway.php` exists and contains a payment-initiation/callback flow, but the inspected `GatewayRegistry` does not register it. Treat it as an adapter implementation present in source, not as a currently exposed WooCommerce gateway, until registration is verified.
- **Foree callback trust boundary:** the inspected callback reads `session_id`, `status` and `order_id` from query parameters and calls `payment_complete()` when status equals `SUCCESS`; no callback signature verification or server-to-server payment-status confirmation is visible in that handler. Verify Foree's authoritative callback protocol and require authenticated confirmation before marking an order paid.
- **AI model routing:** `OpenAiBridge` sends `$req->model` to the OpenAI Chat Completions endpoint, while `GeminiBridge` uses a fixed Gemini model. Verify the provider-selection/container wiring never routes a Gemini model name through the OpenAI bridge.
- **Courier webhook behavior:** `ShippingWebhookHandler` exposes public REST routes and delegates trust to each courier adapter's `verifyWebhookSignature()`. TCS uses HMAC validation; Leopards uses source-IP allowlisting in the reviewed adapter. Confirm the provider's documented signing/IP ranges, proxy behavior and replay protection; invalid requests currently receive a generic 200 response.
- **POS stock behavior confirmed:** `ProcessPOSSaleUseCase` saves the order and items before attempting stock deductions; it catches `DomainException` per item, logs a warning and continues to record the sale/session totals. This is an explicit oversell-tolerant behavior in source, not merely an unconfirmed suspicion. Confirm it is the intended business policy and add reconciliation/alerting so stock divergence is visible.

