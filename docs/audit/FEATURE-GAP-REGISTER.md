# Junction Systems — Feature & Gap Audit Register

**Audit status:** In progress — source review only; runtime tests not yet executed.  
**Baseline:** `main` at `38b6eee00d92b5a7240c62fb3e8d06a3f80c3c4b` (reviewed 15 Nov 2026).  
**Purpose:** Record current behaviour, dependencies, gaps and owner-verification questions before proposing or implementing feature changes.

## Change-control rules

- This is an audit register, not approval to change behaviour.
- No feature, plugin, integration, table, endpoint or legacy code is to be removed until its purpose and dependencies are verified with the project owner.
- No merge or deployment without explicit approval.
- Existing functionality is presumed intentional until verified otherwise.
- The bundled WooCommerce AI Sales Assistant must be assessed as a possible source of WooCommerce product catalogue context; do not label it redundant merely because the theme has a separate chat implementation.
- Deployment model: separate licensed installation per customer, vendor-deployed on vendor hosting; one store owner per installation at a time. This is not a shared multi-tenant SaaS model.
- Store owners supply their own Groq API keys. Junction Systems is not responsible for central Groq billing or central provider quota. Per-store credential protection and provider error handling remain in scope.

## Owner-confirmed deployment and licensing model

**Owner clarification recorded (16 Nov 2026):**

- Junction Systems is not intended to operate as a shared multi-tenant SaaS installation.
- Each licensed customer receives a separate copy/installation of Junction Systems, deployed by the vendor on the vendor's hosting infrastructure (including shared hosting).
- Each installation is used by one store owner at a time. Its WooCommerce store, settings, Groq BYO API key, AI configuration, customer records and chat history belong to that installation.
- The store owner may also create employee/staff accounts within that same installation. Staff access must follow WordPress/WooCommerce roles and least-privilege permissions; this does not make the installation multi-tenant.
- Customer data and credentials must remain isolated between separate installations. No cross-installation customer-data access or shared store configuration is intended.
- Audit architecture, licensing, backups, updates, secrets management, support access and resource limits against this per-customer deployment model. Do not introduce multi-tenant assumptions or infrastructure without owner approval.

## Repository inventory

The recursive Git tree contains 197 tracked files. The feature registry declares these 22 feature flags:

| Group | Feature flags | Default |
|---|---|---|
| Payments | JazzCash, EasyPaisa | On |
| Payments | Foree Pay | Off |
| Checkout | CoD OTP, Dukaan AI Chat | On |
| Couriers | TCS, Leopards, Trax | On |
| Couriers | PostEx, Rider.pk | Off |
| Marketing | Affiliate system | On |
| Marketing | WhatsApp Business API, Mailchimp, Klaviyo | Off |
| Analytics | GA4, Facebook Pixel + CAPI | Off |
| Operations | Inventory, POS, Barcode labels | On |
| Operations | Multi-location stock | Off |
| Integrations | QuickBooks, ERPNext, Daraz sync | Off |

Other substantial source areas discovered: licensing/white-label branding; shipping webhooks and tracking; COD OTP; inventory and stock movement; POS and offline queue; affiliate registration/clicks/commissions/payouts; barcode labels; payment gateways; courier integrations; SMS providers; WhatsApp notifications; Mailchimp/Klaviyo; GA4/Meta Pixel; QuickBooks/Daraz; admin dashboard and reporting.

A feature flag existing in the registry does not prove the feature is fully implemented, wired, licensed, tested, or safe to enable.

## Confirmed preliminary findings

### AI chat and product catalogue — high priority

1. Theme has its own AI chat route and storefront widget. The route is registered when `ai_chat` is enabled, but `footer.php` prints chat markup unconditionally and `boot.mjs` initialises it whenever the markup exists. The flag does not consistently control the visible widget.
2. Service configuration binds `AiProviderInterface` to `OpenAiBridge`, while `HandleAiChatUseCase` sends `gemini-1.5-flash`. `GeminiBridge` exists and reads a Gemini key but is not the bound provider in the inspected configuration. This is a provider/configuration mismatch.
3. Theme already has `WcProductRepository` and `ProductContextService`. It searches published product title/excerpt using up to five extracted keywords, or a small hard-coded category mapping, and supplies up to five products. It reads price, stock, short description, categories, attributes and brand.
4. Theme product resolution is limited: no general catalogue retrieval, relevance/ranking strategy, pagination, explicit variation resolution, or robust handling for broad questions such as “show me all available red shoes” was evident. Keyword matching is a basic SQL LIKE/OR search.
5. Theme product metadata contains hard-coded business facts (PKR currency, COD availability, served cities, delivery time, digital delivery and return policy). These must be checked against each store's actual WooCommerce configuration and policies.
6. Bundled plugin independently builds a catalogue prompt from up to 40 published, in-stock WooCommerce products ordered by popularity, including price, category and short description, cached for 10 minutes. This may answer broad product-discovery questions better than the theme's five-result keyword resolver. It does not expose a shared product repository/API to the theme.
7. Plugin and theme have separate widget markup, JavaScript, settings, API routes/AJAX actions, product-context builders and WhatsApp settings. Enabling both may create two chat launchers and conflicting customer experiences.
8. Plugin catalogue context is limited to 40 popular in-stock products; generated lines omit variant/attribute/brand detail; cache may be stale for up to 10 minutes; fixed store-policy claims are appended to the prompt.
9. Theme and plugin use different WhatsApp sources/defaults and different fallback behaviour. Plugin falls back to a hard-coded phone number; theme uses store settings. Confirm the source of truth before changing either.
10. Theme chat history is persisted in a custom table. UUID is generated client-side and can be regenerated, so UUID-only throttling is bypassable. A trusted server-side/IP limit and abuse strategy need design and hosting-proxy validation.
11. Theme stream client does not currently surface structured provider errors or create WhatsApp handoff for stream/network/provider failures. The blocking request path also does not consistently handle non-2xx responses.
12. Plugin API keys are stored in the normal `woo_ai_settings` WordPress option (not encrypted by the plugin). Theme settings infrastructure supports encrypted sensitive values, but current AI settings form and provider wiring need to be reviewed as one complete flow.
13. Plugin hard-codes model IDs and quota assumptions. Verify current provider support; do not treat those limits as universal. Each store owner manages their own Groq account.
14. Bundling a plugin folder in the theme repository does not itself install or activate it. The inspected theme notice linked to the generic plugin installer rather than installing the bundled package. Verify the intended install/update lifecycle.

### Other preliminary architecture/security observations

- `ThemeBootstrapper::boot()` registers a callback referring to `$this->container`, while the class stores the container as `$this->c`. Verify whether this callback runs and causes a runtime error.
- Feature registry includes `multi_location`, `erpnext`, and `whatsapp_api`; their existence in source does not prove end-to-end implementation. Inspect dependencies and enablement paths.
- `SecurityHardener` globally restricts portions of REST API. Check compatibility with WooCommerce blocks, third-party plugins, webhooks and external integrations.
- WhatsApp webhook handler currently logs incoming payloads but does not implement signature verification or message processing in the inspected code. GET verification appears to be registered under a POST-only route, so Meta verification may not work as intended.
- External integrations need sandbox/production, authentication, retry, idempotency, webhook-signature and failure-path verification before being called production-ready.
- No obvious PHPUnit/Composer or JavaScript package/test setup appeared in the initial tree scan. Confirm CI and local test instructions before concluding tests do not exist.

## Feature-by-feature audit plan

For every feature, record: expected business behaviour confirmed by owner; UI/admin controls; source files and hooks; data tables/options/secrets; dependencies and licensing; security/privacy; failure/retry behaviour; compatibility; test evidence; gaps and proposed options. Do not implement until owner verification is recorded.

1. Core theme, bootstrap, autoloading, activation and compatibility.
2. Licensing, license tiers, feature entitlements and white-label branding.
3. WooCommerce storefront, product catalogue, product pages, cart and checkout.
4. AI chat, product discovery/context, conversation history, Groq BYO key, WhatsApp handoff, bundled plugin lifecycle.
5. Payment gateways: JazzCash, EasyPaisa, Foree.
6. COD OTP and SMS providers.
7. Courier integrations: TCS, Leopards, Trax, PostEx, Rider; shipping labels, rates, booking, tracking and webhooks.
8. Inventory: products, suppliers, purchase orders, stock adjustments, stock movements, multi-location and WooCommerce stock sync.
9. POS: sessions, sales, order creation, offline queue and sync.
10. Barcode generation and printing.
11. Affiliate system: registration, affiliate products, clicks, commissions, payout and fraud controls.
12. WhatsApp Business API: outbound notifications, inbound webhook and AI handoff distinction.
13. Email marketing: Mailchimp and Klaviyo.
14. Analytics: GA4 and Facebook Pixel/Conversions API.
15. Accounting and marketplaces: QuickBooks, ERPNext, Daraz.
16. Admin dashboard, reports, audit logs, exports, setup wizard and feature settings.
17. Security hardening, REST permissions, nonces, rate limiting, privacy/data retention and backups.
18. Frontend performance, accessibility, responsive behaviour, SEO and browser compatibility.
19. Database schema, migrations, uninstall/upgrade lifecycle and backward compatibility.
20. Documentation, deployment, operational monitoring and test/CI coverage.

## Owner verification queue — AI product catalogue

**Owner decisions recorded (15 Nov 2026):**

- The AI must search the full WooCommerce catalogue for relevant products, rather than being restricted to the plugin's 40 popular products or the theme's five keyword-matched results. Example: for “wireless headphones under PKR 5,000”, search the catalogue and recommend relevant matching products.
- The AI must support variable products and check the exact requested variant (for example, black shoes in size 42), including that variant's availability and price, before answering.
- Product answers should include the product SKU, current sale price (where applicable), current stock availability and a direct product link, so customers can open the recommended item in the store.
- For broad recommendation requests (for example, “suggest a good watch”), the AI may recommend popular products, but every recommendation must be grounded in actual WooCommerce catalogue records and current product data. It must not invent products, SKUs, prices, variants, stock claims or product URLs; if it cannot verify a detail, it must say so or ask a clarifying question.
- Customer-facing stock display should default to a simple “In Stock” / “Out of Stock” status. Exact stock quantities may be shown only when the individual store owner explicitly enables customer-facing quantity disclosure.
- If the requested product or exact variant is out of stock, the AI should offer similar available alternatives from the actual WooCommerce catalogue, verifying their current availability and relevant variant details before recommending them.
- The AI must treat a customer's stated budget as a hard maximum by default: do not recommend products above it unless the customer explicitly asks to see higher-priced options. Apply the store currency and actual current product price when filtering.
- The AI should respond in the customer's language and script where reasonably supported: for example, Roman Urdu questions receive Roman Urdu replies, Urdu questions receive Urdu-script replies, and English questions receive English replies. Product facts must remain grounded and must not be altered by translation.
- Within the same chat session, the AI should retain and apply prior customer preferences/context when a follow-up is clearly related (for example, preserve a stated budget when the customer next asks to see a different colour). It should not carry unrelated context into a new topic or make assumptions when the relationship is unclear; ask a brief clarifying question when needed.
- If the AI cannot resolve a customer's issue, or the customer requests a human representative, it should offer a WhatsApp handoff. With the customer's awareness/consent, pass a concise, relevant conversation summary and useful context (such as the product/variant, stated budget and issue) so the customer does not need to repeat everything. Do not expose unrelated conversation history or sensitive data; use the store owner's verified WhatsApp destination.
- Store owners should be able to configure their customer-support phone number, WhatsApp number and support hours in admin settings. The AI should answer support-hours questions using those configured store-specific values, not hard-coded assumptions, and should distinguish open from closed hours using the store's configured timezone.
- Customer-facing stock display should default to “In Stock” / “Out of Stock”. Exact quantities may be shown only when the store owner explicitly enables customer-facing quantity disclosure.

Implementation details (search/index strategy, pagination, variation resolution and stock accuracy) remain subject to technical audit.

Observed alternatives:
- Theme AI searches a small set (up to five) using query keywords/category mapping and richer product fields.
- Bundled plugin AI supplies up to 40 popular in-stock products as a catalogue list; simpler product fields but broader discovery coverage.

Questions to verify before architecture decision:
1. Should Dukaan AI answer only about products matching the customer's query, or also recommend/browse popular products when the query is broad?
2. Should it support variable products and variants (size, colour, storage, pack size), exact stock quantity, SKU lookup, sale prices and product links?
3. Is the bundled plugin expected to be installed/activated on client stores, or is it only a source package intended for a future installer?
4. Should the existing plugin widget remain the customer-facing chat, should the theme widget remain, or should we decide after feature-parity testing? No assumption is made yet.

## Owner verification queue — order support

**Owner decision recorded (16 Nov 2026):**

- The AI should provide a direct courier tracking link alongside order and shipment status when a valid tracking URL is available from the store/courier integration.
- The AI must not cancel WooCommerce orders itself. Cancellation requests must be handed to human support for review and processing, to prevent abuse or customers misusing automated cancellation.
- The AI must not modify WooCommerce orders itself. Requests to change delivery address, phone number, products or quantities must be handed to human support for verification and processing; the AI must not claim a change has been made unless a human has completed it in WooCommerce.

**Proposed behaviour awaiting owner verification:**
- Look up an order using an order number, then verify customer ownership before disclosing order-specific information.
- Provide order status and payment status from WooCommerce.
- Provide courier name, tracking number and current shipment status when available from the connected courier integration.
- Provide delivery estimates only when supported by verified courier data; do not invent dates or shipment events.
- Do not expose personal details such as address or phone number without appropriate verification.
- Include a direct courier tracking link when the integration supplies a valid URL; otherwise explain that a direct link is unavailable.

## Owner verification queue — returns and refunds

**Owner decision recorded (16 Nov 2026):**

- The AI may explain the store's configured return and refund policy using verified, store-specific policy information.
- The AI must not approve returns, authorize refunds, issue refunds or promise that a refund/return has been accepted. Customer requests must be handed to human support for review and processing.
- The AI must not invent eligibility rules, deadlines, fees, refund timelines or exceptions. If the applicable policy is missing or unclear, it should say so and refer the customer to human support.

## Owner verification queue — delivery and shipping information

**Owner decision recorded (16 Nov 2026):**

- The AI may explain the store's configured delivery policy and shipping charges using current, store-specific settings and verified integration data.
- It may provide delivery estimates only when supported by verified data. It must not invent delivery dates, time windows, shipping charges, service coverage or courier events.
- If delivery information is unavailable, stale or uncertain, the AI should clearly say it cannot verify the detail and refer the customer to the configured human support channel where appropriate.

## Owner verification queue — cart and checkout assistance

**Owner decision recorded (16 Nov 2026):**

- The AI may help customers choose products and guide them through adding items to the cart and completing checkout.
- The AI must not place an order or submit checkout on the customer's behalf without the customer's explicit confirmation for that specific purchase.
- The AI must not imply an order has been placed until WooCommerce confirms successful order creation. If the customer completes checkout themselves, the AI may help explain the next steps using verified order information.

## Owner verification queue — payment status verification

**Owner decision recorded (16 Nov 2026):**

- The AI must report payment status only from authoritative, verified WooCommerce order records or the connected payment gateway's verified transaction records.
- A customer message, receipt image, screenshot or claimed transaction reference alone must not be treated as proof that payment succeeded.
- If payment status cannot be verified, the AI must say it is unconfirmed and direct the customer to the configured human support channel or the appropriate payment provider process. It must not mark an order paid or claim payment success.

## Owner verification queue — payment failures and pending transactions

**Owner decision recorded (16 Nov 2026):**

- If a payment attempt fails, times out or remains pending, the AI must explain only the status supported by verified WooCommerce or payment-gateway records.
- The AI must not claim that a payment was successful, failed permanently or was charged again unless the authoritative transaction records confirm that outcome.
- Where the status is unresolved or the customer needs help, the AI should direct the customer to the configured human support channel and provide a concise, relevant handoff context with the customer's awareness/consent.
- The AI must not initiate a duplicate charge or tell the customer that another charge has been made. Any retry must follow the payment provider's supported flow and require the customer's own action/confirmation.

## Owner verification queue — COD OTP verification

**Owner decision recorded (16 Nov 2026):**

- Cash on Delivery orders must follow the configured OTP verification requirement before they are treated as verified/confirmed.
- The AI must not bypass the OTP requirement, generate or disclose OTPs, or independently mark an OTP as verified.
- OTP validation must be performed by the trusted OTP verification flow and its authoritative result. If OTP delivery or verification fails, the AI should explain the verified state and route the customer to the configured support process rather than bypassing the control.

## Owner verification queue — spam and suspicious order abuse

**Owner decision recorded (16 Nov 2026):**

- The system should help detect suspicious repeated orders, OTP abuse and automated spam attempts using auditable signals and configurable safeguards.
- Detection must not automatically block a genuine customer solely because an activity is flagged as suspicious. Use proportionate friction, verification or human review, with a clear recovery path for legitimate customers.
- Rate limits and abuse controls must be enforced server-side; client-generated identifiers alone are not sufficient.
- Record relevant security events without unnecessarily retaining or exposing sensitive customer data. False positives and recovery/support handling must be included in testing.

## Owner verification queue — customer data privacy and access control

**Owner decision recorded (16 Nov 2026):**

- The AI should access only the minimum customer/order data necessary to answer the current request or complete an explicitly authorized support workflow.
- It must never disclose another customer's orders, phone number, address, payment details or other personal information.
- Order-specific information and personal data must be disclosed only after an appropriate, server-side customer ownership/identity verification step. A supplied order number alone is not sufficient proof of ownership.
- Conversation context sent to a human-support handoff must be limited to relevant information, with customer awareness/consent; unrelated history and sensitive data must not be included.
- Access controls must be enforced server-side, not merely hidden in the frontend or delegated to AI prompt instructions. Audit and test for cross-customer data leakage and unauthorized direct API access.

## Owner verification queue — central client-installation monitoring

**Owner clarification recorded (16 Nov 2026):**

- There will be one central platform-owner panel from which all client deployments are managed.
- The panel is the control plane for every deployment: assign the purchased package, switch that package's included features ON/OFF, manage licensing, and manage client payments.
- Each deployment must receive and enforce its own package entitlements and feature configuration. A client cannot self-enable features excluded or disabled by the platform owner.
- The central panel must also show each deployment's license status, activation status, expiry and technical/deployment health.
- Licensing/payment state and technical health must remain distinct: payment or license status must not be inferred from deployment health, and health must not be inferred from payment status.
- Central access and cross-client records must be restricted to authorized platform-owner personnel. Client installations must not be able to view or alter another client's deployment, license, package or payment records.
- Any package/feature change should be auditable and securely propagated to the correct installation, with a visible success/failure state. A failed sync must not silently leave the central panel and client installation showing conflicting entitlements.

- **Owner decision confirmed (16 Nov 2026):** The central panel must support package upgrades and downgrades for an individual client deployment. After the platform owner changes the package, the new package's feature entitlements/configuration should automatically propagate to that specific installation and take effect there.
- Package changes must be deployment-scoped, authenticated, auditable and observable. The central panel should show propagation/application success or failure; on failure, it must not silently report the new package as active while the client still enforces old entitlements. Preserve data and settings for features that are temporarily disabled or removed by a downgrade unless a separately approved migration/data-retention policy says otherwise.

## Owner-provided current package catalogue and pricing

**Official website reference:** `https://juncsys.com` (identified by the owner as the Junction Systems website). The owner supplied the current pricing/package copy in this conversation; direct automated retrieval of the site was unavailable during this audit session, so the supplied copy is the source of truth for this record until the live page can be independently reviewed.

- **Owner clarification:** The marketing website also contains working feature demos. Treat these demos as behavioural/UX references for intended workflows, not merely promotional screenshots. When access is available, exercise each relevant demo and record observed inputs, outputs, workflows and limitations; compare those observations against the actual application's `main` branch. A demo does not by itself prove that the corresponding production feature is implemented, secure or integrated in the application.
- The website is not the application source of truth: use GitHub `main` for actual implementation evidence, website demos for expected behaviour/UX, and the pricing page for advertised package commitments. Record any mismatch as a verification gap rather than assuming either side is correct.


**Owner-provided package details recorded (16 Nov 2026):**

The currently advertised plans are monthly subscriptions, with hosting, SSL, monthly backups, installation/integration setup, bug-fixing support and inventory-management support described as included. No setup fee; cancel anytime.

| Plan | Advertised monthly price | Included features as supplied |
|---|---:|---|
| Basic | PKR 25,000/month | Hosting; free SSL; monthly backups; installation and integration setup; bug-fixing support; inventory-management support; JazzCash + EasyPaisa; TCS + Leopards + Trax; COD + SMS OTP; affiliate system; white-label branding; email support (48-hour response); Junction POS; inventory management; Dukaan AI Chat; WhatsApp Business API; QuickBooks / Daraz sync. |
| Standard (Most Popular) | PKR 55,000/month | Everything in Basic; hosting; free SSL; monthly backups; installation and integration setup; bug-fixing support; inventory-management support; Junction POS (offline-first); inventory-management system; barcode generator + label printer; Dukaan AI chat (Urdu + English); Google Analytics 4; Facebook Pixel + CAPI; Mailchimp; WhatsApp support (24-hour response); PostEx + Rider.pk couriers; WhatsApp Business API; QuickBooks / Daraz sync. |
| Enterprise | PKR 95,000/month | Everything in Standard; hosting; free SSL; monthly backups; installation and integration setup; bug-fixing support; inventory-management support; PostEx COD remittance; Rider.pk same-day delivery; WhatsApp Business API automation; QuickBooks Online sync; Daraz marketplace sync; Klaviyo email flows; multi-location inventory; Foree Pay card terminal; dedicated account manager; priority support (12-hour SLA). |

- The supplied call-to-action for all three plans is `https://juncsys.com/#contact`; advertised terms are “No setup fee · Cancel anytime”.
- At purchase/checkout, the client must be able to select the billing cycle themselves: monthly or yearly. The selected cycle, applicable package price, annual discount (if yearly), invoice schedule and license term must be clearly displayed before payment and recorded against that client subscription.
- Both monthly and yearly subscription billing should be offered. The owner confirmed an annual discount: **12 months of service for the price of 10 months (2 months free)**. Calculate the annual price per package as 10 × its monthly price; show the saving transparently on the invoice/checkout. Annual package prices derived from the currently supplied monthly rates are Basic PKR 250,000/year, Standard PKR 550,000/year and Enterprise PKR 950,000/year. These are before any separately applicable taxes/fees, which remain to be confirmed.
- The plan list contains repeated foundational services and overlapping integration/features. Before implementing the entitlement matrix, normalize each feature into one canonical entitlement and confirm its tier availability, dependencies, support/SLA meaning and whether “Everything in [lower plan]” is intended to inherit all lower-tier entitlements.
- In particular, Basic explicitly lists several integrations/features that are also repeated in Standard or Enterprise. Do not silently infer that repeated mentions are exclusive upgrades or remove them from lower tiers.
- The advertised support response times (48-hour email, 24-hour WhatsApp and 12-hour Enterprise priority SLA) need precise definitions, business-hours/timezone rules, start/stop conditions and exclusions before they become enforceable SLAs.
- “Hosting”, SSL, backup cadence/retention, storage/resource limits, restore support, inventory-management support and bug-fixing scope need operational definitions and capacity limits per plan before contracting or automation.

## Owner verification queue — client invoicing and payment history

**Owner decision confirmed (16 Nov 2026):**

- The central platform panel should automatically generate invoices for each client deployment.
- Each invoice should clearly identify the assigned package, package charges, billing period, issue date, due date and payment status.
- The central panel should retain a per-client invoice and payment history, including paid, unpaid, overdue and applicable adjustment/credit records.
- Invoice generation and payment records must be tied to the correct client account and deployment, with role-restricted access so clients cannot view another client's billing information.
- Invoice totals and status must be traceable to the applicable package/pricing and verified payment records. Do not mark an invoice paid from an unverified customer claim or screenshot.
- Both monthly and yearly subscription billing options should be supported. The applicable cadence should be selectable according to the package/client agreement and recorded against that client's subscription.
- Currency/tax treatment, proration for mid-cycle package changes, invoice numbering, payment methods and credit-note/refund workflow remain to be verified before implementation.

## Owner verification queue — overdue payments and automatic license enforcement

**Owner decision confirmed (16 Nov 2026):**

- When a client's payment becomes overdue, the system should automatically send a payment warning/notice.
- The grace period after the overdue warning is **7 days (one week)**; if payment is still not received when that period ends, the affected client installation should be automatically suspended according to its licensing/payment status. The grace period should be centrally configurable for future policy changes.
- Once payment is received and verified through an authoritative payment-gateway notification or an authorized, auditable manual confirmation, the license/installation should automatically reactivate and the central panel should show the updated status.
- Do not reactivate based only on a customer screenshot, message or unverified payment claim.
- Warning schedules, grace-period duration, payment reconciliation, webhook signature verification, retries/idempotency and suspension/reactivation events must be configurable or explicitly specified and auditable.
- During suspension, the client/store owner must retain access to a restricted billing/recovery area in the admin panel to view the outstanding amount, complete payment and check license/reactivation status. The storefront and licensed operational features remain suspended until payment is verified and reactivation succeeds. Recovery access must be narrowly scoped and must not expose a way to bypass licensing or enable suspended features.
- Suspension must not silently delete customer/store data, orders, configuration, chat history or feature data. Define the exact customer-facing restricted state and recovery/payment path; preserve data unless a separately approved retention/deletion policy applies.
- If payment confirmation or license-sync delivery fails, show a clear pending/error state in the central panel and avoid falsely reporting successful reactivation. Retry safely without duplicate payment processing or conflicting entitlement state.

## Owner verification queue — platform-level package and feature administration

**Owner clarification recorded (16 Nov 2026):**

- Junction Systems platform owner/vendor controls package entitlements and enables/disables the complete set of product features for each licensed client installation, not AI alone.
- The vendor configures each customer's installation according to the purchased package/license, including which modules, integrations and premium capabilities are available.
- Platform-controlled configuration includes package/feature entitlements, AI enable/disable, Groq API key and model configuration, licensing controls and deployment setup.
- The customer/store owner manages their own business-related settings within the enabled features, such as store information, product/business policies, support contacts/hours, operational preferences and other settings intentionally exposed for client configuration.
- Store employees may access only the business/support functions explicitly granted to their role. They must not automatically receive access to platform-level package entitlements, feature toggles, provider credentials or licensing controls.
- Feature entitlements and platform-level controls must be enforced server-side across admin screens, REST/AJAX endpoints, background jobs and direct routes; hiding a menu or UI control is not sufficient.
- A client must not be able to enable an unlicensed feature by changing a request, option, URL, database value or frontend state. Disabled features must fail closed without breaking unrelated licensed functionality.
- Keep platform-owner controls distinct from client business settings in the admin UX, capability checks, documentation, upgrade/deployment process and support procedures.


## Owner verification queue — chat history and admin access

**Owner decision recorded (16 Nov 2026):**

- Store owners should be able to view customer chat history in the admin panel to investigate support issues and maintain service continuity.
- Chat-history access must be permission-controlled and limited to authorized store personnel; staff should not receive broader access than their role requires.
- The system must provide configurable data-retention controls and a documented deletion/expiry process consistent with applicable privacy requirements.
- Chat records should be protected in storage and transit, and access to customer conversations should be auditable. Avoid exposing unrelated customer conversations or sensitive data in admin views, exports or support handoffs.
- The customer-facing privacy notice should explain that chats may be stored and reviewed by authorized store support staff.

## Deep source audit — architecture and confirmed code-level findings

**Review pass recorded (16 Nov 2026):** Re-read the current `main` tree and inspected bootstrap, dependency wiring, feature registry, licensing, settings, database installers, REST controllers, payment/courier integrations, AI flows, POS/inventory, WhatsApp hooks and admin setup. This is static source inspection only; findings below require runtime verification before any repair.

### Repository identity and architecture

- Private repository: `haseeb1984/Junction-Systems`; default branch: `main`.
- Reviewed `main` baseline/tree: commit `38b6eee00d92b5a7240c62fb3e8d06a3f80c3c4b`; recursive tree contains 197 tracked files.
- The code is a WordPress/WooCommerce theme (theme header/readme identify PK Store Theme v1.0.1), written in PHP with ES modules and custom CSS. Minimum stated requirements: WordPress 6.4+, WooCommerce 8.0+, PHP 8.2+.
- Main bootstrap: `functions.php` → PSR-style custom autoloader → `config/services.php` singleton service container → `ThemeBootstrapper`. Theme activation runs the custom database installer and registers custom WooCommerce order statuses.
- Major bounded areas present in source: payment gateways, COD OTP/SMS, courier booking/tracking/webhooks, AI chat, affiliate, inventory, POS, barcode, analytics, WhatsApp, email marketing, QuickBooks, Daraz, branding, feature flags and license classes.
- This is a single-store WordPress/WooCommerce installation per customer, not a multi-tenant application. The central multi-deployment control plane requested by the owner is not present as a complete module in the inspected source tree.

### High-priority findings — bootstrap, feature flags and licensing

1. **Bootstrap property mismatch:** `ThemeBootstrapper` stores the service container as `$this->c`, but the `pkstore_require_feature` callback accesses `$this->container`. If that action is invoked, it will fail. The callback also appears to be the only visible bridge to `LicenseManager::requireFeature()`.
2. **Feature flags are local options, not central entitlements:** `FeatureManager` reads/writes `pkstore_features` in the individual WordPress database. Its settings screen allows a local `manage_options` user to enable/disable features. There is no observed signed central entitlement sync or platform-owner-only enforcement.
3. **License manager is not wired into normal bootstrap:** `LicenseManager` and `LicenseAdminUI` exist, but the inspected `functions.php`, service bindings and `ThemeBootstrapper` do not instantiate/register the license UI or bind the manager. The feature callback is not connected to the normal route/controller registration paths.
4. **Feature/entitlement enforcement is incomplete:** `ThemeBootstrapper` conditionally registers some modules using local feature flags, but several registries are unconditional; e.g. payment gateway and shipping method registration does not filter individual methods by feature. The visible admin settings can be changed locally.
5. **License implementation appears incomplete/inconsistent:** `LicenseManager` imports `LicenseExpiredException`, `LicenseDomainMismatch`, `LicenseInvalidException` and `LicenseFeatureNotAvailable`, but the recursive `main` tree contains only `src/License/Exception/LicenseException.php`; the imported sibling exception classes were not found. Its remote URL is `license.junctionsystems.pk/api/validate`, product identifier is `pk-store`, and the key/cache are stored locally. No matching central billing/invoice/payment control-plane implementation was found in the inspected tree.
6. **License cache/grace semantics need review:** local cache is accepted for 24 hours; offline grace starts when remote validation fails and lasts 7 days. This is an offline validation grace, not the owner's separately confirmed 7-day payment-overdue grace. These must not be conflated.
7. **Tier matrix mismatch:** `LicenseFeatures::BY_TIER` assigns Basic/Standard/Enterprise feature lists, but it does not match all owner-supplied public plan details or the 22-key `FeatureManager` registry. Several feature slugs differ between the two systems (for example `jazzcash_payment` vs `payment_jazzcash`, `dukaan_ai` vs `ai_chat`, and `inventory_management` vs `inventory`). No complete mapping/enforcement bridge was observed.
8. **Activation lifecycle:** `functions.php` uses `after_switch_theme` for database setup; no `register_activation_hook` exists in the theme (expected for a theme), and no upgrade-version migration runner was observed in the inspected bootstrap. Installer writes DB version `2.0.0`, but migration execution/version comparison needs a dedicated audit.
9. **License UI exposes the decrypted full license key** in the client WordPress admin page. Confirm intended client visibility and mask/reveal policy.
10. **License remote protocol/security:** HMAC uses a per-install option key, but the inspected code has no visible request nonce/anti-replay timestamp, key rotation protocol or central webhook/push channel. Review server-side counterpart before designing the platform control plane.

### High-priority findings — payments and logistics

1. `GatewayRegistry` unconditionally registers both `JazzCashGateway` and `EasyPaisaGateway`, but the recursive tree does not contain `src/Infrastructure/Payment/EasyPaisaGateway.php`. This is a likely class-loading/runtime failure when WooCommerce initializes the registered gateway. Verify in a WooCommerce runtime.
2. `ForeePayGateway` exists, but it is not registered by the inspected `GatewayRegistry`; no Foree setup fields were present in the inspected `SettingsManager` sections. The public Enterprise promise of Foree Pay is therefore not demonstrated end-to-end.
3. JazzCash callback verifies HMAC before calling `payment_complete`, but returns HTTP 200 even for missing/invalid signatures; transaction/order amount, currency and replay/idempotency checks need deeper review.
4. Foree callback trusts query-string `status=SUCCESS` and `order_id` to call `payment_complete`; no signed callback verification or server-to-server transaction verification was observed. Treat as critical payment-integrity risk until verified.
5. `ShippingRegistry` unconditionally registers TCS, Leopards and Trax shipping methods. Feature-specific flags are not applied in this registry.
6. The circuit-breaker booking path is constructed with TCS, Leopards and Trax only. PostEx and Rider classes exist but are not wired into this circuit breaker in the inspected service configuration. No Rider courier implementation was found in the tree.
7. PostEx class supports booking and COD remittance retrieval, but no complete booking hooks, configuration UI, remittance reconciliation workflow or automated schedule was demonstrated in the inspected paths.
8. Courier webhook routes are public by design, but must authenticate every request. TCS/Trax attempt HMAC validation; Leopards uses a hard-coded source-IP allowlist. The common handler returns HTTP 200 even when signature validation fails, obscuring rejected delivery attempts and retry diagnosis.
9. `ShippingRegistry::autoBookShipment` schedules `pkstore_retry_courier_booking` with argument key `order_id`, while the bootstrap callback shown accepts an integer positional argument. Verify Action Scheduler argument compatibility; retry flow may receive an array rather than the expected integer.
10. Courier retry/idempotency, COD remittance matching, cancellation, rate quotes, label retrieval and tracking URL generation need integration-level tests with sandbox credentials. Do not treat class existence as proof of production integration.

### High-priority findings — AI and customer experience

1. The theme AI service binding points to `OpenAiBridge`, while the use case hard-codes model `gemini-1.5-flash`; the setup wizard exposes a Gemini API key. This provider/model/key mismatch prevents a coherent configured provider flow.
2. `functions.php` says Dukaan AI is now handled by the bundled WooCommerce AI Sales Assistant plugin, while the theme still contains its own AI controller, routes, product context, chat table and storefront widget. The two implementations are independent and can conflict or duplicate the widget.
3. The bundled plugin is not installed/activated by virtue of being in the theme ZIP. The admin notice links to the generic WordPress plugin installer; no bundled plugin installer/updater was observed.
4. Theme product retrieval is capped at five keyword/category matches and does not resolve variable-product variants explicitly. Plugin context is capped at 40 popular in-stock products, omits exact variation detail, and caches for 10 minutes. Neither path meets the owner's full-catalogue/exact-variant requirement yet.
5. Theme store metadata hardcodes PKR, COD, nationwide coverage, 2–5 business days and a return policy. Plugin prompt hardcodes free delivery threshold, same-day cities and 7-day returns. These are not safely grounded in each store's actual settings.
6. Plugin Groq model IDs are hard-coded in the plugin constants and need current provider/model compatibility verification. Plugin API key is stored in the regular `woo_ai_settings` option; it is not encrypted by the plugin.
7. Theme chat rate limit keys off a client-provided session UUID, which can be regenerated; plugin IP detection trusts forwarded headers unless proxy trust is constrained, and counters use non-atomic transient read/modify/write. Both need server-side abuse controls and trusted proxy handling.
8. Theme chat history is persisted by session UUID. Ownership/authentication binding, retention/deletion and admin review controls need verification before production use. Customer consent/notice and minimal handoff data are not yet fully implemented.
9. Plugin human WhatsApp button and AI handoff are separate from the theme's AI flow. The plugin uses a fallback hard-coded WhatsApp number if no configured number exists. This conflicts with the requirement to use a verified store-specific destination only.
10. WhatsApp Business API inbound handler currently logs request JSON only; it does not process messages. GET verification code exists inside a route registered as POST-only, so Meta's GET verification challenge cannot reach that branch. Signature validation for inbound POST webhook was not observed.
11. The theme stream endpoint catches all errors and emits only `[DONE]`, so customers receive no structured failure/handoff message for provider/network errors.

### High-priority findings — inventory, POS, OTP, affiliate and security

1. Database installer creates OTP, logistics, chat, city mapping, affiliate and inventory tables on theme switch. It does not demonstrate a versioned migration/rollback strategy; DB schema changes on existing deployments need explicit upgrade handling.
2. Inventory/POS use separate custom tables and repositories. WooCommerce stock synchronization is implemented as a use case, but verify hook registration, product mapping, variable-product stock behaviour, concurrency/atomicity and reconciliation before claiming reliable two-way sync.
3. POS REST routes accept users with `manage_woocommerce OR edit_posts`; several session/order handlers do not visibly verify that the requested session belongs to the current cashier or that location access is authorized. This is a potential privilege boundary issue.
4. POS sale accepts client-supplied unit price/discount values (the use case falls back to product price but accepts posted override); server-side price authority, discount permissions, quantity validation, idempotency and offline queue conflict handling need audit.
5. Inventory REST routes are restricted to `manage_woocommerce`, but feature licensing/entitlement is not checked in those handlers. Product create, stock adjustment and location actions require deeper validation of quantities, movement types and ownership.
6. COD OTP endpoints use a public storefront nonce as their permission check and accept phone/order ID from the request. Review order existence, order/payment method, phone-to-order matching, order ownership, resend limits, IP/device controls and whether OTP verification is mandatory before order confirmation. A nonce is not customer identity verification.
7. Affiliate product listing is public and returns submitted product data/redirect links. Affiliate registration requires login; commission/payout state transitions and admin approvals need end-to-end review. Verify that commission creation is idempotent and tied to eligible paid orders.
8. Security hardener restricts anonymous REST access to broad path prefixes `/wc/` and `/wp/v2/`, potentially affecting WooCommerce Store API, blocks, webhooks and plugins. Compatibility matrix/runtime tests required.
9. Login brute-force counters use remote address directly; reverse-proxy trust, shared-IP false positives, atomic increments and recovery controls need review.
10. Several user-facing outputs and integration logs contain customer phone/order/product details. Review log redaction, retention, access controls and export handling.

### Marketing package claims versus source implementation — first-pass mismatch register

- Basic advertises JazzCash + EasyPaisa, but EasyPaisa gateway class was not found in the current source tree; Basic also advertises Dukaan AI and WhatsApp Business API while current feature defaults/configuration and separate AI implementations need reconciliation.
- Standard advertises offline-first Junction POS, barcode labels, GA4, Facebook Pixel + CAPI, Mailchimp, PostEx and Rider.pk. POS and barcode source exists; GA4/Facebook/Mailchimp/PostEx classes exist; Rider implementation was not found. End-to-end offline-first behaviour, CAPI server events and integration hooks remain unproven.
- Enterprise advertises PostEx COD remittance, Rider same-day delivery, WhatsApp automation, QuickBooks Online, Daraz, Klaviyo, multi-location inventory and Foree Pay. Some corresponding classes exist, but the Rider implementation is absent, Foree is not registered, PostEx remittance is only a fetch method in the inspected class, and multi-location enforcement/sync is not proven.
- “Hosting, SSL, monthly backups, installation, bug fixing and inventory support” are operational services and cannot be verified from the theme source alone. They need deployment/hosting/backup/support runbooks and central operations evidence.
- These are preliminary evidence-based mismatches, not permission to remove or disable any feature.

### Audit limits and next review passes

- This pass reviewed source text and the full tracked-file inventory; it did not execute PHP, WordPress, WooCommerce, browser flows, external payment/courier sandboxes or production webhooks.
- Continue with focused review of every domain: payment gateways and callbacks; courier API payloads and webhook signatures; OTP repository/use cases; inventory schema/repositories; POS offline queue and cashier permissions; affiliate commission/payout lifecycle; integration services (GA4, Meta CAPI, Mailchimp, Klaviyo, QuickBooks, Daraz); admin templates and JavaScript; CSS/accessibility; dependency compatibility and release/deployment process.
- Verify missing/renamed classes and PHP syntax/autoloading with static tooling and a clean WordPress/WooCommerce test installation.
- Do not infer a module is production-ready from class/file existence, admin menu presence, feature flag presence or marketing claims.

## Status

- Initial source inventory and first AI/product-context review completed.
- Owner-confirmed deployment model: one separately licensed, vendor-deployed installation per customer on vendor hosting; one store owner per installation at a time. This is not a shared multi-tenant SaaS deployment. AI feature decisions are recorded in the owner-verification sections above.
- Full feature-by-feature source review: pending.
- Runtime/integration/security tests: pending.
- Owner feature verification: pending.
- No production changes or merges performed as part of this audit.


### Focused source pass — POS, inventory, affiliate and external integrations

**Review pass recorded (16 Nov 2026):** Inspected the corresponding controllers, application use cases, repositories, database tables and integration service classes on `main`. This remains static review; no live gateway/API credentials or WordPress runtime were used.

#### POS and inventory

1. POS is a custom-table workflow, not a demonstrated WooCommerce-native POS order pipeline. It creates rows in `pkstore_pos_orders` and line-item tables; the reviewed sale use case did not create a WooCommerce order.
2. The POS sale endpoint accepts client-supplied `unit_price_pkr`, per-item discount and sale-level discount. The use case copies the supplied unit price rather than deriving it exclusively from the authoritative WooCommerce product/variation price. This permits price tampering unless another uninspected layer validates it.
3. The sale use case catches stock-adjustment `DomainException` and continues the sale, explicitly allowing a POS sale to complete even if stock deduction fails/stock goes below zero. The owner must decide the stock-negative policy, but inventory consistency requires a visible exception/reconciliation path.
4. The POS sync queue currently marks pending custom POS orders as synced; the inspected `SyncPOSQueueUseCase` does not create corresponding WooCommerce orders or transmit orders to an external server. “Offline-first”/sync-to-WooCommerce is therefore not established by this path.
5. POS controller grants access to either `manage_woocommerce` or `edit_posts`. Session close, sale, order-list and receipt handlers do not consistently check that the session/location belongs to the current cashier or that the cashier has access to that location. Session close is particularly sensitive because it accepts a session ID.
6. POS receipt WhatsApp endpoint constructs and returns a `wa.me` URL; it does not send a WhatsApp Business API message. It trusts posted receipt item names, quantities and totals for the message.
7. Inventory schema includes products, locations, stock levels, stock movement ledger, suppliers, purchase orders and POS records. The presence of these tables is useful groundwork, but supplier/purchase-order receiving and multi-location reconciliation require separate workflow tests.
8. Multi-location is a feature flag, but no enforcement was seen in the reviewed POS controller. POS accepts a caller-supplied location ID, defaults to location 1 and relies on repository/use-case behaviour; authorization and location ownership need to be enforced server-side.
9. Barcode label page is registered in admin bootstrap without a visible `barcode_labels` feature check. Inventory and POS are locally gated by `FeatureManager`, but licensing is not enforced at these routes.

#### Affiliate system

1. Affiliate registration requires login and records a JazzCash/EasyPaisa payout destination. The affiliate dashboard returns the affiliate's payment account and commission/payout details to the logged-in affiliate; verify masking and account-change verification.
2. Public affiliate product listing exposes active affiliate-submitted product records and tracked redirect URLs. Review moderation, destination URL validation, prohibited content and abuse prevention before public launch.
3. Payout use case creates a payout record and attempts JazzCash transfer; EasyPaisa is not implemented in the inspected payout path and instead fails to an admin manual-payment email path.
4. Payout processing has a likely PHP syntax defect in the JazzCash URL expression: the selected base URL is followed by a string literal without a concatenation operator. Validate with PHP lint; if confirmed, the payout class cannot be parsed.
5. Commission repository supports lookup by order ID and status updates, but the reviewed code needs an idempotency/unique database constraint and proof that commissions are created only for eligible paid/settled orders. No full reversal/refund/chargeback reconciliation was demonstrated in this pass.

#### Analytics and marketing integrations

1. GA4 client-side event output contains a malformed `view_cart` JavaScript value expression (an extra quote after the cart total). Purchase event is emitted on the order-received page and also via Measurement Protocol on payment complete; these two paths can double-count without a shared transaction-level deduplication strategy.
2. GA4 and Meta Pixel purchase paths mark a post-meta “fired” flag before confirming the browser event was delivered. A page/network failure can therefore suppress a retry. The server-side GA4/Meta event paths also need event-ID/deduplication alignment with browser events.
3. GA4 and Meta events hard-code PKR. Confirm store currency and ensure consent/privacy preferences are respected before loading tracking scripts or sending server-side customer identifiers.
4. Meta CAPI hashes email/phone, but the inspected payload does not include browser event ID/fbc/fbp matching signals or a deduplication event ID. The browser and server purchase events may be counted separately.
5. Mailchimp subscribes the billing email as `subscribed` on checkout/order processing and on payment complete. The reviewed path does not check marketing consent or distinguish transactional order sync from marketing-list opt-in. This is a consent/compliance risk.
6. Mailchimp order sync uses a hard-coded PKR currency and its request method construction and endpoint composition need API-level verification. HTTP status/error responses are not surfaced beyond null JSON.
7. Klaviyo service similarly adds profiles to a list and emits events on payment complete without a visible marketing-consent gate. Cart event is only emitted for logged-in users; API revision and payloads need live validation.
8. QuickBooks and Daraz service classes exist, but source presence is not evidence of OAuth lifecycle, token refresh, conflict resolution, retry queues, webhook reconciliation or safe two-way sync. Daraz product push is capped at 100 products and uses placeholder/default package dimensions and a generic brand; order import creates WooCommerce orders without adding product line items in the inspected method.
9. Daraz request signing and XML body construction require sandbox verification. Product-feed code uses raw product SKU/name/description values in XML and should be reviewed for proper XML escaping and variation handling.
10. No dedicated integration health dashboard, last-success timestamp, dead-letter queue or operator retry workflow was demonstrated for these marketing/accounting/marketplace connectors.

#### Code quality and validation items surfaced

- At least one likely parse-time defect was identified in affiliate payout URL concatenation; run PHP lint across every PHP file before runtime acceptance.
- The source pass surfaced malformed/fragile generated JavaScript in GA4 cart tracking; run JS syntax checks on all shipped modules and emitted inline scripts.
- API integrations need contract tests against current provider sandbox/API versions; class construction and local method calls alone are insufficient.
- Feature flag checks, license entitlement checks, role permissions, consent checks and per-cashier ownership must be treated as separate controls, not assumed to be interchangeable.


### Focused source pass — courier operations, settings and dependency wiring

**Review pass recorded (16 Nov 2026):** Inspected service-container bindings, setup wizard/settings storage, courier classes, WooCommerce shipping methods, QuickBooks and Daraz services. Static review only; provider sandboxes were not contacted.

#### Courier and shipping

1. TCS, Leopards and Trax WooCommerce shipping methods currently return fixed shipping costs (PKR 250, PKR 220 and PKR 210 respectively). They do not call the courier APIs or calculate a live destination/weight-based rate in their `calculate_shipping()` methods. Treat these as placeholder flat rates, not live courier quotations.
2. `ShippingRegistry` registers all three methods unconditionally; the reviewed shipping-method classes do not check their matching feature flag or license entitlement.
3. The automated booking circuit breaker is wired only to TCS, Leopards and Trax. Although PostEx and Rider classes are bound in the service container, they are not members of that circuit breaker and are not selected by the reviewed normal auto-booking path.
4. PostEx class has a shipment-create call and a COD-remittance retrieval call, but no remittance reconciliation/settlement matching, scheduled retrieval, admin reconciliation screen or retry workflow was demonstrated.
5. Rider class has a shipment-create call and a hard-coded four-city same-day list. It uses a default 1kg parcel weight and a fixed “Same day” fallback estimate. No Rider webhook/tracking implementation or configuration tab was found in the reviewed setup wizard.
6. The setup wizard exposes JazzCash, EasyPaisa, TCS, Leopards, Trax, SMS and AI tabs, but does not expose PostEx, Rider, Foree, QuickBooks, Daraz, Mailchimp, Klaviyo, GA4, Meta CAPI, WhatsApp Business API or multi-location configuration in the reviewed tabs. Some values may be configurable elsewhere, but an end-to-end client setup path is not demonstrated.
7. Courier tracking controller accepts a tracking number and queries only TCS, Leopards and Trax. No PostEx/Rider tracking resolver is wired into this endpoint.
8. Webhook handling and booking need a full provider-by-provider contract pass: signature canonicalization, IP allowlist source, replay protection, duplicate events, status transition ordering, tracking-number uniqueness and reconciliation with WooCommerce orders.
9. The circuit breaker returns a successful-looking pending shipment result after all courier attempts fail, and schedules a retry. This is an intentional resilience pattern in source, but the retry callback argument shape and customer/admin status visibility must be verified so “pending booking” is never presented as a confirmed shipment.

#### Settings, secrets and dependency configuration

1. `SettingsManager` supports environment variables, encrypted options and plain options. Sensitive values are encrypted with AES-256-CBC using a key derived from WordPress `AUTH_KEY` and site URL; unlike authenticated encryption, CBC alone does not provide ciphertext integrity. Review migration, key rotation, tamper detection and backup/restore behaviour.
2. Setup Wizard's AI section stores a Gemini API key, while the actual bound AI provider is `OpenAiBridge`; the bundled plugin uses a separate Groq key option. This confirms three competing provider configuration paths (Gemini theme setting, OpenAI bridge and Groq plugin setting) that need a single owner-approved provisioning model.
3. Settings wizard's setup-completion calculation checks only JazzCash merchant credentials and TCS credentials, despite the wizard presenting many other sections. A “complete” indicator therefore does not establish that a client deployment is operational.
4. The setup wizard allows a `tab` query parameter and renders a generic no-settings message for unknown tabs; no major issue by itself, but the settings inventory is incomplete compared with advertised packages.
5. Service container binds PostEx and Rider classes, but does not wire them into the active courier failover path. It binds QuickBooks/Daraz/marketing/analytics services; their registration is gated by local feature flags, not central licensing.
6. Service container binds EasyPaisa gateway class, but the repository tree does not contain the referenced class file. A separate autoload/class existence check is required before treating EasyPaisa as functional.
7. The setup and configuration paths must be assessed against the owner's deployment model: platform owner controls licensed features, AI provider/model credentials and deployment settings, while store owner controls business-specific settings. The current local `manage_options` settings/feature pages do not enforce this division.

#### Accounting and marketplace sync

1. QuickBooks service uses a cached OAuth access token and refresh token, but no visible OAuth authorization-code connection/onboarding flow was found in the inspected settings wizard. It posts sales receipts using hard-coded QuickBooks ItemRef, CustomerRef and account-independent assumptions; product/customer mapping and tax/currency handling are not implemented in the reviewed method.
2. QuickBooks sync is triggered on WooCommerce payment complete and uses an order meta marker for basic duplicate prevention. Failure retry, token-refresh concurrency, void/refund sync, inventory sync, multi-currency and error dashboard are not demonstrated.
3. Daraz product push reads at most 100 WooCommerce products, sends a generic brand and hard-coded package dimensions/weight, and constructs an XML request from product data. It does not demonstrate full-catalogue pagination, variable-product/SKU mapping or robust XML escaping.
4. Daraz order import checks duplicate marketplace order ID, then creates a WooCommerce order with basic billing details. The inspected method does not create WooCommerce line items, map marketplace SKUs to store products, reconcile totals/payment/shipping, or implement fulfillment/status updates.
5. “QuickBooks Online sync” and “Daraz marketplace sync” are therefore partial connector code paths, not proven bidirectional/production-grade synchronization.

#### Release/runtime quality gate

- Run `php -l` over all PHP source files and `node --check`/equivalent over ES modules and generated inline scripts before any deployment. This is especially important because the source review already identified likely parse/syntax and emitted-JavaScript defects.
- Verify every class imported or referenced by bootstrap/service bindings exists and is autoloadable, including EasyPaisa and license exception classes.
- Create a clean WordPress 6.4+/WooCommerce 8+ test site using PHP 8.2+ and run activation, upgrade, theme switch, checkout, order status, gateway callbacks, shipping, inventory, POS, affiliate, admin permissions and uninstall/backup restore tests.
- Test actual client hosting constraints (shared hosting, cron reliability, Action Scheduler, PHP extensions, outbound HTTP, memory/time limits, SSL and backup restoration); source code cannot prove the advertised hosting/backup/support service.
