# Juncsys / Junction Systems — Implemented Feature Register

> Source-reviewed capability map for the WordPress/WooCommerce business platform. Integration code paths are distinguished from live provider-account acceptance. This is not a fresh production test report.

## Platform foundation
- WordPress theme/plugin PHP architecture.
- WooCommerce catalogue, cart, checkout and order integration.
- Shared WordPress/WooCommerce data persistence.
- External payment, courier, AI and marketing-provider integration points.

## Point of Sale (POS)
- Cashier session workflow.
- Sale creation and item/quantity validation.
- Split-payment handling.
- Receipt generation.
- Sale/order history.
- Offline operation queue and synchronization when connectivity returns.
- POS sale flow invokes inventory stock movement.

## Inventory
- Multi-location stock management.
- Stock adjustments and movement records.
- Purchase-order workflows.
- Low-stock tracking.
- Barcode generation.
- WooCommerce stock synchronization.

## Ecommerce
- Product catalogue browsing.
- Cart and checkout.
- Physical and digital product support.
- WooCommerce order flow.
- Payment integration paths.

## Payments and fulfilment
- JazzCash payment path.
- EasyPaisa payment path.
- Cash-on-delivery OTP verification path.
- Courier integration architecture for TCS, Leopards and Trax.
- Payment/courier integration code does not by itself prove live merchant credentials, webhook acceptance or production account activation.

## Dukaan AI
- AI chat controller and product-aware context assembled from catalogue data.
- Urdu and English response support.
- AI provider bridge.
- Streaming response support where configured.
- Prompt-injection checks and response safeguards.

## Growth and marketing
- Affiliate/referral registration and tracking.
- Commission and payout workflows.
- Mailchimp integration.
- Klaviyo integration.

## Explicit verification boundaries
- Do not infer provider production acceptance from adapter/code presence.
- Confirm exact roles, permissions, settings, webhook events, retry rules and supported provider/model combinations from source before expanding this register.
- Do not describe a provider integration as live unless its production configuration and end-to-end operation have been verified.
