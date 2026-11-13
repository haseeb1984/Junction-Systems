# Juncsys / Junction Systems

Business platform built around WordPress and WooCommerce, combining POS, inventory, ecommerce and Dukaan AI.

Product site: https://juncsys.com

## Technology and dependencies
- WordPress theme/plugin PHP architecture.
- WooCommerce catalogue, cart, checkout and order integration.
- External payment, courier, email-marketing and AI provider adapters.
- No root-level Composer or Python requirements manifest was identified in the reviewed repository. Runtime prerequisites are WordPress, compatible PHP and the required WooCommerce/plugin environment; verify the production versions before deployment.

## Core feature areas
- POS cashier sessions, sales, split payments, receipts and offline queue synchronization.
- Multi-location inventory, stock movements, purchase orders, low-stock alerts, barcode generation and WooCommerce stock sync.
- Ecommerce catalogue, cart and checkout for physical/digital products.
- Dukaan AI Urdu/English product-aware chat and AI provider integration.
- JazzCash, EasyPaisa and COD OTP payment paths.
- TCS, Leopards and Trax courier integration architecture.
- Affiliate/referral commissions and payout workflows.
- Mailchimp and Klaviyo integrations.

## Architecture and flow
See [`docs/PROJECT_CONTEXT.md`](docs/PROJECT_CONTEXT.md) for POS, inventory, ecommerce and Dukaan AI diagrams and end-to-end flows, plus roadmap and code-review findings.

## Setup and operations
Install within a compatible WordPress/WooCommerce environment. Configure provider credentials securely and validate gateway/courier accounts separately. Back up WordPress files and database before deployment.

## Review notes
The project context separates confirmed source capabilities from hardening findings, including POS capability boundaries, stock consistency, AI provider/model routing, automated test coverage and version metadata. These observations require validation and do not imply that the owner-tested product is non-functional.


## Project documentation
- [Implemented feature register](docs/FEATURES.md)
- [Roadmap](docs/ROADMAP.md)
- [Gaps and verification register](docs/GAPS.md)
- [Architecture and project context](docs/PROJECT_CONTEXT.md)
- [Dependencies](docs/DEPENDENCIES.md)

