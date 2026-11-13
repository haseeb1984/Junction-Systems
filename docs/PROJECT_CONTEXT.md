# Juncsys / Junction Systems — Project Context

> Product site: https://juncsys.com. Source review of private repository `haseeb1984/Junction-Systems`, branch `main`, reviewed 2026-11-13. Owner identifies POS, Inventory, Ecommerce and Dukaan AI as the core product areas.

## Purpose
WordPress/WooCommerce-oriented business platform combining POS, inventory, ecommerce, AI-assisted shopping/support and business integrations.

## Stack and dependencies
- WordPress theme/plugin PHP architecture with WooCommerce integration.
- PHP Composer configuration was not present at repository root in the inspected tree; WordPress/WooCommerce runtime dependencies are supplied by the host/plugin environment.
- External service credentials belong in environment/configuration, never committed source.

## Architecture
```mermaid
flowchart TB
 U[Store owner / cashier / shopper] --> WP[WordPress + WooCommerce]
 WP --> POS[POS application/controller]
 WP --> INV[Inventory services]
 WP --> EC[Catalog / cart / checkout]
 WP --> AI[Dukaan AI chat controller]
 POS --> UC[Application use cases]
 INV --> UC
 AI --> CTX[Product context service]
 CTX --> LLM[AI provider bridge]
 LLM --> SEC[Prompt-injection / response safeguards]
 UC --> DB[(WordPress / WooCommerce data)]
 POS --> PAY[Payment gateway adapters]
 EC --> PAY
 PAY --> EXT[Payment providers]
 INV --> LOG[Courier / stock sync adapters]
 EC --> MKT[Mailchimp / Klaviyo integrations]
```

## Main flows
### POS
1. Cashier opens/uses a session.
2. Sale use case validates items, quantities and payment splits.
3. Stock movement is attempted and sale/receipt/order history is recorded.
4. Offline queued operations can synchronize when connectivity returns.

### Inventory
1. User manages stock by location.
2. Adjustments and purchase orders create stock movement records.
3. Low-stock tracking and barcode generation support operations.
4. WooCommerce stock synchronization keeps ecommerce inventory aligned.

### Ecommerce and fulfilment
1. Customer browses WooCommerce catalogue and adds items to cart.
2. Checkout processes order/payment.
3. Payment adapters support JazzCash, EasyPaisa and COD OTP verification paths.
4. Courier adapters include TCS, Leopards and Trax integration architecture.

### Dukaan AI
1. Chat request is received by AI controller.
2. Product context is assembled from catalogue data.
3. Prompt-injection checks and provider bridge process the request.
4. Urdu/English response is returned, including streaming response support where configured.

## Feature inventory (source-reviewed)

The full capability list is maintained in [FEATURES.md](FEATURES.md). The repository source includes:

- WordPress/WooCommerce platform bootstrap, service container, settings, licensing, feature entitlements, branding and admin surfaces.
- POS sessions, sale processing, split payments, receipts/order history and offline queue synchronization.
- Multi-location inventory, stock levels/movements, adjustments, suppliers, purchase orders, barcode labels and WooCommerce stock synchronization.
- WooCommerce catalogue, cart, checkout, order metadata and payment/shipping registries.
- Payment abstractions and JazzCash/ForeePay adapters; COD OTP send/verify, local/Twilio SMS providers and OTP audit. Existing project context also records an EasyPaisa path; production registration/activation must be separately confirmed.
- Courier shipment/tracking/webhook abstractions and TCS, Leopards, Trax, PostEx and Rider adapters, plus city mapping, rates and circuit-breaker support.
- Dukaan AI / Woo AI Sales Assistant plugin, product-context service, chat use case, OpenAI/Gemini bridges, Urdu/English response path and prompt-injection safeguards.
- Affiliate registration, product submission, click tracking, commissions, payouts, admin pages and affiliate dashboard.
- Facebook Pixel, GA4, QuickBooks, Mailchimp, Klaviyo, Daraz and WhatsApp integration services/hooks.
- Database installers, operational logging and admin configuration.

Provider adapters are source capabilities, not proof of live credentials, external account approval or production acceptance.

## Roadmap
1. Tighten POS authorization to dedicated financial-operation capabilities and role checks.
2. Define transactional consistency policy when stock deduction fails during sale (reject, reserve, or explicitly allow overselling).
3. Add automated tests for POS totals, split payments, inventory invariants, checkout and offline sync.
4. Validate AI provider/model routing and document supported provider/model combinations.
5. Add webhook/idempotency and reconciliation tests for payment/courier callbacks.
6. Document plugin/theme installation, WooCommerce prerequisites, environment settings and backup/restore.

## Gaps and verification register
- Inspected POS permission callback accepted `manage_woocommerce` OR `edit_posts`; the latter may be too broad for financial operations.
- POS sale path appeared able to continue if stock deduction fails; confirm intended overselling/consistency behavior.
- One AI service path appeared to send a Gemini model identifier through an OpenAI provider; verify provider/model routing and whether the path is active.
- No dedicated automated test files were found in the inspected tree.
- Theme metadata version and internal `PKSTORE_VERSION` appeared inconsistent; verify release/versioning convention.
- These are code-review findings to validate, not proof that the owner-tested product is non-functional.
