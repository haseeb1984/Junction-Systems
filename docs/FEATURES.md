# Juncsys / Junction Systems — Implemented Feature Register

> Reconciled against the WordPress theme/plugin source tree, application use cases, domain entities, provider adapters and admin/customer templates. Provider code paths do not by themselves prove live merchant credentials or production account acceptance.

## Platform foundation and administration
- WordPress theme/plugin PHP architecture integrated with WooCommerce.
- Service-container/autoloader and theme bootstrap.
- Settings manager and setup wizard.
- Feature manager and license manager/admin UI.
- Admin menu and dashboard services.
- Branding manager and admin branding settings.
- Feature settings UI.
- Security hardening/bootstrap support.
- WooCommerce asset enqueuing and order metadata integration.

## Point of Sale (POS)
- POS application/controller and cashier-facing POS interface.
- POS session domain and persistence.
- POS order/sale domain and persistence.
- Sale-processing use case.
- Split-payment support as recorded in project context.
- Receipt and order-history workflow.
- Offline POS queue synchronization use case.
- POS order/session repository adapters.

## Inventory and stock control
- Inventory products and location entities.
- Multi-location stock levels.
- Stock movement records.
- Stock adjustment use case.
- Purchase-order and supplier domain models.
- Inventory admin screen.
- Stock log and purchase-order admin screens.
- Barcode generation use case and barcode-label presentation.
- WooCommerce inventory hooks and stock synchronization use case.
- Inventory repositories and installer/schema support.

## Ecommerce and checkout
- WooCommerce product catalogue, cart, checkout and order integration.
- Physical and digital product support (as recorded in project context).
- Checkout template customization.
- WooCommerce order metadata integration.
- Payment gateway registry and gateway adapters.
- Storefront styles/assets for shop and checkout.

## Payment and COD verification
- Payment gateway abstraction and request/result/status objects.
- JazzCash gateway adapter.
- ForeePay gateway adapter.
- COD OTP send and verification use cases.
- OTP domain records, expiry/invalid/duplicate/rate-limit exception handling.
- Local SMS provider and Twilio SMS provider adapters.
- OTP audit admin table and COD OTP controller.
- Current `GatewayRegistry` registers JazzCash and references `EasyPaisaGateway::class`, but no EasyPaisa class file was found in the tracked source tree; resolve this discrepancy. A ForeePay gateway class exists under `Payment/Foree`, but is not registered by the inspected registry. Neither should be described as an active checkout gateway until wiring and provider verification are complete.
- Gateway adapter presence does not prove merchant account onboarding, production credentials or successful live settlement.

## Shipping, courier and tracking
- Courier provider abstraction and shipment/tracking DTOs.
- Shipment booking use case.
- Tracking update/controller and shipping webhook handler.
- Courier adapters for TCS, Leopards, Trax, PostEx and Rider.
- City mapping service.
- Shipping rate calculator.
- Courier circuit breaker and logistics logging.
- WooCommerce shipping methods/registry for TCS, Leopards and Trax.
- Logistics admin screen and list table.
- Courier provider production access and webhook acceptance require external verification.

## Dukaan AI / Woo AI Sales Assistant
- Dedicated Woo AI Sales Assistant plugin and chat widget.
- Admin and widget components.
- AI chat controller and chat use case.
- Product context service and WooCommerce product repository.
- Context selection and prompt composition.
- AI provider interface and OpenAI/Gemini bridge implementations.
- Chat request/response/message value objects.
- Prompt-injection detector and dedicated exception handling.
- Urdu/English product-aware conversation and streaming support as recorded in project context.
- Product-aware response path is distinct from general-purpose, ungrounded chat.

## Affiliate and referral commerce
- Affiliate registration use case.
- Affiliate and affiliate-product entities/repositories.
- Affiliate product submission.
- Affiliate click and product-click tracking.
- Commission recording and commission-rate value object.
- Payout processing and payout/balance validation.
- Affiliate admin screens for affiliates, products, commissions and payouts.
- Affiliate dashboard and product grid.
- WooCommerce affiliate hooks.

## Analytics, accounting and marketing integrations
- Facebook Pixel service.
- Google Analytics 4 service.
- QuickBooks accounting service.
- Mailchimp service.
- Klaviyo service.
- Daraz marketplace synchronization service.
- WhatsApp hooks and WhatsApp service.
- These integrations remain subject to their configured credentials, scopes and external service behavior.

## Licensing, configuration and observability
- License feature/entitlement definitions and license manager.
- Admin feature configuration.
- Application logger and logistics logger.
- Database installers for platform, inventory and affiliate schemas.
- Operational logging and settings infrastructure.

## Evidence and scope notes
- Feature names above are grounded in controllers, use cases, entities, repositories, adapters and templates present in the source tree, plus the existing project context.
- Do not interpret an adapter or interface as proof of live production provider activation.
- The project context's payment and courier references should be maintained alongside exact adapter registrations as they are verified.
- Security, stock consistency, AI routing and test coverage findings remain verification/hardening items; they do not imply that the owner-tested product is non-functional.


## Source-level workflow detail (reconciled with repository update 2026-11-14)

### POS REST and cashier workflow
- Authenticated WordPress REST routes under `pkstore/v1` expose session open/close/current, sale processing, queue sync, session order listing and WhatsApp receipt-link generation.
- Opening a session resumes an existing open session for the location or creates one for the current WordPress user; closing records closing cash and reports sales/cash variance.
- Sale requests carry product/quantity items, payment method, customer details, PKR discount and optional split-payment data; the use case enriches items from the inventory catalogue, creates the order and records item rows.
- POS queue synchronization is implemented as a separate use case; see the verification register for its current behavior (it changes pending records to synced rather than replaying a sale).
- POS REST authorization currently accepts either `manage_woocommerce` or `edit_posts`; this is the source rule, not a claim that a dedicated cashier role exists.
- WhatsApp receipt endpoint builds a `wa.me` URL from the supplied phone/order/items/total; it returns a link and message rather than sending WhatsApp directly.

### Inventory REST and stock workflow
- Inventory REST endpoints support paginated/searchable product listing, product detail, product search, barcode/SKU lookup, product creation, stock adjustment, per-location stock, location listing, barcode generation and low-stock listing.
- Product responses expose SKU/barcode/type, name/category, PKR cost/sale price, total stock, reorder point, unit and WooCommerce product association.
- Stock adjustments update location stock, record a stock-movement ledger entry and attempt synchronization to the linked WooCommerce product when applicable.
- Inventory and POS endpoints use the `manage_woocommerce` capability in the reviewed controller.
- Inventory installer creates separate product, location, stock-level and stock-movement tables; POS installer creates session/order storage.

### COD OTP verification workflow
- REST endpoints send and verify a six-digit COD OTP and require the `pkstore_cod_otp` WordPress nonce.
- Sending is limited to three requests per phone in a 15-minute window, invalidates the prior active code for the phone/order, stores an OTP attempt and sends via the configured SMS provider.
- OTP attempts have a five-minute validity window; verification tracks attempts, rejects expired/already-verified codes and reports remaining attempts.
- Digital-only orders bypass phone OTP verification. Checkout JavaScript blocks COD order submission until the phone is verified, except for digital-only orders.
- Twilio and local SMS provider adapters are available; actual provider delivery depends on configuration and account availability.

### Storefront JavaScript behavior
- ES-module boot code loads checkout COD, tracking, cart, affiliate and lazy-loaded AI chat modules only when their corresponding page/widget selectors exist.
- Cart module submits WooCommerce AJAX add-to-cart requests and updates the visible cart count/event bus.
- Tracking widget normalizes the entered tracking number, calls the tracking REST endpoint, renders bilingual English/Urdu status and event information, and caches results in session storage for five minutes.
- COD checkout module handles OTP send/verify/resend, six-digit input, resend countdown, error states and COD submission gating.

### AI assistant paths
- Theme source contains an AI chat REST controller with nonce validation, a per-session-UUID hourly request counter (20 requests/hour), JSON and SSE streaming routes, prompt-injection response handling and product-context use cases.
- The bundled Woo AI Sales Assistant plugin is also present. Its engine calls Groq, builds a context from up to 40 popular in-stock WooCommerce products, applies session/RPM/RPD/TPM limits and temporary backoff, and provides WhatsApp handoff when limits/provider errors occur.
- The theme's `functions.php` explicitly directs new deployments to the bundled/installed WooCommerce AI Sales Assistant plugin; keep the theme controller and plugin engine documented as distinct code paths rather than assuming they are one runtime implementation.

### Affiliate workflow
- Logged-in users can register as affiliates with JazzCash or Easypaisa payout-account details, view their own dashboard, submit affiliate products and track referral/product clicks.
- Affiliate dashboard returns commission history, payout history, product status/clicks and earned/paid/pending totals.
- Affiliate installer creates affiliate, affiliate-product, commission and payout tables; the theme bootstrap feature-gates affiliate REST/hooks and admin screens.

### Bootstrap and feature gates
- Theme bootstrap always registers tracking, shipping webhook handling, payment/shipping registries and asset enqueuing; COD OTP, AI chat, affiliate, inventory, POS, analytics, marketing, accounting and Daraz integration are enabled according to feature flags.
- Admin bootstrap registers dashboard/menu, affiliate and inventory screens, feature settings, setup wizard, barcode-label and export configuration surfaces.
- The installer creates OTP, logistics log, chat-history and city-mapping tables, seeds city mappings, and invokes affiliate and inventory installers.
