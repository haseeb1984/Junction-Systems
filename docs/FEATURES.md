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
- Existing project context also records EasyPaisa payment path; verify the current adapter/registration location before claiming live provider activation.
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
