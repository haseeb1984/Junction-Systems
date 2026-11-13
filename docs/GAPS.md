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



## Payment and courier callback re-check against current tracked tree

- **EasyPaisa registration unresolved at repository-tree level:** `GatewayRegistry::register()` adds `JunctionStore\\Infrastructure\\Payment\\EasyPaisaGateway::class`, but a recursive listing of the tracked repository tree contains no `EasyPaisaGateway` source file. The class may be supplied by an external package or deployment autoloader, but no such source/package mapping was confirmed in this pass. Treat the registration as unresolved and do not advertise EasyPaisa as a working gateway until the runtime class is proven.
- **JazzCash callback has a concrete integrity check:** unlike the Foree callback, `JazzCashGateway::handleCallback()` recomputes an HMAC-SHA256 using the configured integrity key, excludes `pp_SecureHash`, sorts the `pp_*` fields and uses `hash_equals` before completing an order on response code `000`. This is positive source evidence for callback authenticity. Still verify the exact provider-required canonical field ordering/encoding, amount/currency/order-reference binding, replay/idempotency handling and non-success status behavior against the current JazzCash contract. Initiation response code `000` only marks the order pending; final completion is callback-driven.
- **Foree callback remains materially weaker:** `ForeePayGateway::handleCallback()` trusts query-string `session_id`, `status` and `order_id`; on literal `SUCCESS` it calls `payment_complete($sessionId)` without a visible signature verification or server-to-server transaction-status/amount/currency check. A browser redirect is not sufficient payment proof. Add provider-authenticated verification and bind the confirmed transaction to the expected order, amount and currency.
- **Foree gateway wiring is not shown in the inspected registry:** a class exists at `src/Infrastructure/Payment/Foree/ForeePayGateway.php`, but the inspected `GatewayRegistry` registers only JazzCash and EasyPaisa. Confirm whether another hook registers Foree before describing it as an active checkout option.
- **Shipping webhook security is delegated to adapters:** REST routes for TCS, Leopards and Trax are publicly callable (`permission_callback => __return_true`), and handler accepts an event only when the selected adapter's `verifyWebhookSignature()` returns true. Invalid signature, missing tracking number, unmatched order and missing order all return HTTP 200 with `status: ok`, which may intentionally suppress provider retries but hides rejected events from callers. Verify each adapter's exact signature algorithm, timestamp/replay window, key rotation, raw-body requirements and logging/alerting; add replay protection and observable rejection telemetry where provider contracts require it.
- **Courier status updates are order-meta matched:** handler looks up an order by the courier-specific tracking meta key/value, maps provider status through `TrackingStatus::fromCourierCode`, then updates WooCommerce status. Verify tracking-number uniqueness, duplicate/out-of-order event handling, and provider-specific status mapping. Delivered COD orders also receive delivery metadata and emit `pkstore_cod_delivered`.
- **POS stock shortfall behavior confirmed:** `ProcessPOSSaleUseCase` saves the order and items before attempting per-item stock deductions. A `DomainException` during stock adjustment is logged as a warning and the loop continues; session totals are still updated and the sale returned. This is an explicit oversell-tolerant flow in the current use case, not an atomic transaction. Confirm it is intended policy and provide reconciliation/alerts or transactional rollback if stock must never go negative.


## Re-audit against updated repository commit (source-reviewed 2026-11-14)

- **Affiliate payout source has a PHP syntax error:** `src/Application/Affiliate/ProcessPayoutUseCase.php` builds the JazzCash API URL with a missing string-concatenation operator between the selected base URL expression and `/ApplicationAPI/API/2.0/Purchase/DoMWalletTransaction`. Run PHP lint/build validation and correct this before invoking the payout use case; the feature's presence in source does not establish a runnable payout path.
- **Affiliate payout supports only the JazzCash branch in the inspected use case:** when an affiliate selects `easypaisa`, the current conditional returns `false` rather than calling an EasyPaisa payout adapter. The affiliate registration accepts both JazzCash and Easypaisa account types, so the UI/account options overstate automated payout coverage unless Easypaisa is intentionally manual.
- **POS queue sync does not replay/process pending sales:** `SyncPOSQueueUseCase::execute()` retrieves pending orders and marks them synced; it does not invoke the injected `ProcessPOSSaleUseCase` or transmit the queued order to a remote service. Confirm what “sync” is intended to mean and implement/reconcile the actual target operation before claiming offline-to-server replay.
- **POS session ownership is not checked in the reviewed controller actions:** open/current are location-based, while close and order listing accept caller-supplied session IDs. The capability check is broad (`manage_woocommerce OR edit_posts`) and does not itself prove that a cashier can access only their own session/location. Confirm the intended multi-cashier/location policy and enforce it server-side.
- **POS receipt WhatsApp endpoint trusts caller-supplied receipt data:** it constructs a WhatsApp URL from request phone, order reference, total and item data rather than loading/verifying the corresponding saved order. Treat the endpoint as link generation, not proof that a valid order was paid or that a message was sent.
- **AI has two code paths:** the theme registers an AI REST controller when its `ai_chat` feature flag is enabled, while `functions.php` points new deployments to the Woo AI Sales Assistant plugin. The container binds `AiProviderInterface` to `OpenAiBridge`; a Gemini bridge class exists but was not selected in the inspected service binding. Verify which chat implementation is enabled in the deployed theme/plugin configuration and avoid duplicate/conflicting chat endpoints.
- **Theme/plugin provider setup remains environment-dependent:** bundled Woo AI plugin requires its Groq API key and the configured SMS, courier, payment, marketing and accounting integrations require provider credentials/scopes. Source presence alone does not verify live provider acceptance or settlement.
- **COD OTP nonce and rate-limit boundaries:** the COD endpoints require a WordPress nonce; the use case limits sends to three per phone per 15 minutes and expires codes after five minutes. Verify attempt-count lockout, phone/order binding and replay behavior with automated tests; the frontend alone is not the security boundary.
- **Inventory stock consistency:** stock adjustment updates the stock record, writes a movement and then syncs the linked WooCommerce product. Review transaction boundaries and failure recovery across those writes so a failed sync does not leave inventory and storefront quantities divergent.
- **Affiliate product listing is publicly callable:** `/affiliate/products/list` uses `__return_true` while registration, dashboard and product submission require login. Verify the public response only exposes approved/active product fields and cannot leak affiliate account or payout data.
- These are static source observations against the repository's current `main` commit, not runtime test results. Owner-reported working behavior should be preserved while these paths are tested and reconciled.
