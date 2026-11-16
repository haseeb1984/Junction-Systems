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


### Focused source pass — REST security, COD OTP and stock consistency

**Review pass recorded (16 Nov 2026):** Inspected REST permission callbacks, security hardening, COD OTP controller/use cases/repository, inventory stock mutation and affiliate hooks. Static review only.

#### COD OTP — verification does not yet establish order ownership or server-side checkout enforcement

1. COD OTP endpoints are public storefront REST endpoints protected by a WordPress nonce. A public page nonce is a CSRF control, not proof that the caller owns the WooCommerce order or controls the phone number.
2. Send/verify handlers accept caller-supplied order ID and phone. The reviewed controller does not load the order for normal physical orders and compare the supplied phone with the order's billing phone, confirm the order is still pending checkout, or confirm COD is the selected payment method.
3. Digital-only bypass checks whether the supplied order ID refers to a digital-only order, but does not bind the request to an authenticated customer/session or prove ownership. Review whether an attacker can use a known digital order ID to receive a successful bypass response.
4. The OTP verification use case marks only the OTP attempt row verified. The reviewed code does not persist a verified flag/token against the WooCommerce order or make server-side order creation/payment validation depend on successful verification. The checkout JavaScript's `state.isVerified` is client-side state and can be bypassed by a crafted checkout request unless a server-side checkout validation hook independently enforces OTP.
5. OTP send limit is three sends per phone per 15 minutes; OTP expires after five minutes and allows three verification attempts. Repository update uses an optimistic attempt-count condition, but send counting/invalidation and concurrent verification/resend need race-condition tests.
6. COD checkout UI derives order ID from a page meta element. Confirm how a not-yet-created checkout order ID is populated in a real WooCommerce checkout, including classic checkout vs Blocks/Store API; the current template emits an empty `data-order-id` attribute.
7. The success response says the CoD order is confirmed, but the inspected verify path only verifies the phone/OTP; it does not itself confirm/create/update the order. Customer-facing wording must not overstate the transaction state.

#### Inventory stock and WooCommerce synchronization

1. Stock adjustment performs read → in-memory mutation → database update → movement insert without a database transaction or atomic SQL update. Concurrent POS/online orders can race and lose stock updates or produce inconsistent movement history.
2. `getStock()` creates a missing stock row after a read; concurrent first-time access can race on the unique product/location key. Insert failures are not checked.
3. Stock update and movement insert return values are not checked, so a failed movement-ledger write can leave the stock quantity changed without its audit record.
4. WooCommerce sale hooks listen to payment complete, processing and completed. A single order can trigger the same deduction path more than once; the reviewed method does not store a per-order/per-line idempotency marker. Duplicate stock deduction is a risk.
5. The same `SyncWooCommerceStockUseCase` is called on order status/payment hooks and its full hourly sync writes the sum of all custom location stock into WooCommerce product stock. Confirm the intended online/physical stock allocation model, order status transitions, refunds/cancellations/restocks and variable-product handling before relying on the result.
6. POS and online WooCommerce sales both mutate custom stock through different hooks/use cases. Their interaction, duplicate event prevention, stock reservations and reconciliation are not yet proven.

#### REST and privilege boundary

1. The security hardener exempts any request URI containing broad path fragments such as `/pkstore/v1/chat` or `/pkstore/v1/webhooks/` from the anonymous REST restriction. Individual endpoints still need their own correct permission/signature checks; path-prefix exemption itself does not authenticate them.
2. Login throttling keys directly off `REMOTE_ADDR`; proxy-aware client IP handling, atomic counters, IPv6 normalization and shared-IP lockout recovery need testing.
3. POS and inventory routes expose operational data and actions under broad WordPress capabilities. The owner-confirmed staff least-privilege model requires explicit cashier, stock clerk, manager and owner capabilities plus resource-level checks (session/location), not just `edit_posts` or `manage_woocommerce`.
4. The review did not find a consistent audit trail for who changed prices, adjusted stock, closed a POS session, changed integration credentials or accessed sensitive chat/order data.

#### Affiliate source-code validation

- The inspected `AffiliateHooks::trackRef()` source appears to contain a malformed string/quote sequence in the `$_GET['ref']` fallback expression. Confirm with PHP lint; if the literal source matches the fetched text, it is a parse-time failure for that class.
- Commission recording is triggered on both payment-complete and order-completed hooks. The use case performs a prior lookup by order ID, but the commission table needs a database-level unique constraint and transaction/locking to make concurrent duplicate events safe.
- The payout use case may automatically attempt payout immediately when an affiliate crosses the eligibility threshold. This needs explicit owner confirmation and a tested approval/fraud/chargeback policy before being treated as intended production behaviour.


### Focused source pass — admin UX, dashboard, AI prompt and remaining runtime blockers

**Review pass recorded (16 Nov 2026):** Inspected admin controllers/templates, dashboard queries, barcode printing, AI prompt/context/provider bridges and feature-settings copy.

#### Admin and operational dashboard

1. Admin navigation and several operational screens use broad `manage_woocommerce` or `manage_options` capabilities. There is no demonstrated custom role/capability matrix for store owner, cashier, inventory clerk, customer-support agent and Junction Systems platform operator.
2. Feature Settings page explicitly says disabled features are “completely unloaded” and claims payment gateways/couriers/integrations are removed. Source inspection contradicts this: gateway/shipping registries are unconditional, and some feature-dependent pages/hooks are registered without their own feature check.
3. Feature Settings is writable by any local `manage_options` user and persists `pkstore_features` directly. This conflicts with the owner-confirmed rule that only the Junction Systems platform owner may control package entitlements and feature activation.
4. Dashboard summary is a local operational overview (orders today, booking failures, OTP activity, AI chat counts and courier delivery ratios). It is not a central multi-deployment dashboard and does not monitor license status, subscription invoices, payment state, package sync or remote installation health.
5. Dashboard values are cached for one hour; “Refresh Stats” is exposed as a query-string action. Validate capability/nonce and operational freshness under real support usage.
6. The OTP audit list and logistics logs contain operational/customer-linked data. Verify masking, retention, export restrictions and least-privilege access.
7. Purchase-order schema/template exists, but the reviewed inventory controller/admin navigation did not demonstrate complete supplier, purchase-order creation, receiving, partial receipt, stock posting and cancellation workflows. Table/template presence is not a completed procurement module.
8. Barcode label output supports A4 HTML and ZPL, but no printer discovery/driver integration or physical label-printer compatibility tests were found. Treat “label printer” as printable output formats pending hardware verification.

#### AI prompt and provider consistency

1. Theme prompt hard-codes the persona as “PK Store” and states PKR, nationwide COD for physical products, instant digital delivery and Urdu/English language behaviour. These assumptions conflict with the owner's requirement that store-specific facts come from the client's configured store settings and that Roman Urdu be answered in Roman Urdu.
2. Prompt instructs the AI to use only the product data supplied, but current theme retrieval supplies at most five products and plugin retrieval at most 40 popular in-stock products. The prompt safety rule cannot compensate for incomplete catalogue context.
3. Theme provider bridge is named/configured as OpenAI, reports model `gpt-4o-mini`, but the application use case passes a Gemini model identifier. Gemini bridge exists but is not selected by the service container. Setup wizard stores a Gemini key, not the OpenAI key read by the bound bridge.
4. AI chat dashboard is based on persisted chat rows and does not establish provider health, latency, errors, model usage/cost, unresolved conversations or human handoff success.
5. AI streaming path buffers provider stream output in memory before yielding parsed deltas; it is not true incremental pass-through to the customer and can increase memory/latency on shared hosting.
6. The theme AI prompt and plugin AI prompt are different, so the same store can provide inconsistent delivery, return, COD, language and product answers depending on which widget responds.

#### Additional source-level blockers to confirm with automated lint

- `FacebookPixelService::register()` as fetched appears to contain an escaped variable token in the callback array (`[\\$this, ...]`). If present in the literal PHP file, it is a PHP parse error.
- `AffiliateHooks::trackRef()` appears to contain an extra quote in the empty-string fallback. If present in the literal PHP file, it is a PHP parse error.
- `ProcessPayoutUseCase::payJazzCash()` appears to be missing a concatenation operator between the selected base URL and the API path. If present in the literal PHP file, it is a PHP parse error.
- `LicenseManager` imports several exception classes that are absent from the recursive `main` tree. License activation/validation may fail at runtime when those exception types are instantiated or caught.
- `GatewayRegistry` and service configuration reference an EasyPaisa gateway class absent from the recursive `main` tree. This needs class existence/autoload testing.
- These are source-text findings, not yet lint-confirmed. The next technical gate is an automated syntax/autoload scan over the exact repository checkout.

#### Central platform scope gap

- The inspected `main` tree contains per-install license classes and a local store operations dashboard, but no complete central platform application for customer deployments, package catalogue, feature entitlement management, recurring monthly/yearly subscriptions, invoices, payment reconciliation, 7-day overdue grace/suspension, restricted billing recovery, deployment health or signed entitlement propagation.
- The license URL points to a remote endpoint, but the central service implementation is outside this repository. Its API contract, security, data model, payment automation and operational reliability remain unverified and must be audited separately before treating the current client-side license manager as complete.


### Focused source pass — storefront JavaScript, tracking privacy, webhook and database install

**Review pass recorded (16 Nov 2026):** Inspected the shipped ES modules, API client, checkout OTP frontend, tracking UI/controller, courier webhook handler, database installers and asset enqueuer. Static source review only.

#### Storefront JavaScript and checkout integration

1. COD checkout prevention is implemented in browser JavaScript through `state.isVerified`. This is not a security boundary: a direct Store API/classic checkout request can bypass browser-side state unless an independent server-side checkout validation verifies a server-persisted OTP proof bound to customer/order/phone/payment method. No such enforcement was established in the inspected controller/use case.
2. Checkout JavaScript binds to classic WooCommerce selectors and a custom `data-cod-section`. Compatibility with WooCommerce Checkout Blocks/Store API, express payment methods, checkout field refresh, order-pay and guest checkout has not been demonstrated.
3. `AssetEnqueuer::deregisterJQuery()` globally deregisters jQuery, jQuery Core and jQuery Migrate on every non-admin frontend page. This can break WooCommerce core scripts, themes, payment gateways, analytics and third-party extensions that declare jQuery dependencies. Run a plugin/theme compatibility matrix before enabling this optimisation.
4. `assets/js/lib/api-client.mjs` retries any non-API network failure once, including POST requests. A request may have reached the server even when the response was lost; retrying OTP send, stock adjustment or other non-idempotent operations can duplicate side effects unless every endpoint uses idempotency keys.
5. The API client sends a JSON body for POST requests and includes a nonce in that body. Confirm all REST routes read the body consistently and do not expect standard `X-WP-Nonce` headers; cross-origin/proxy caching and preflight behaviour also need validation.
6. Cart add-to-cart code posts directly to `/?wc-ajax=add_to_cart`, does not inspect `response.ok` or WooCommerce's JSON error result, and increments the visible cart count regardless of whether the product was actually added. It also does not send a WooCommerce nonce or update fragments/variation data. The UI can report a false cart success.
7. Boot module imports `./modules/affiliate.mjs` when affiliate markup exists, but that module is absent from the recursive `main` tree. The affiliate storefront widget therefore has a failed dynamic import unless the file is supplied externally (no such build step was found in the repository).
8. Boot uses `Promise.allSettled`, which hides module failures from the user and logs only to console. There is no production-facing telemetry for failed feature initialization.
9. Tracking UI labels Urdu/English together and caches results in sessionStorage for five minutes. The server response contains only the latest event, not a full courier event history; stale tracking data can be displayed until TTL expires.

#### Tracking endpoint and customer data exposure

1. Tracking REST permission callback verifies a publicly available WordPress nonce only. It does not bind the tracking number to the current customer/order, nor does it require a verified email/phone or another ownership factor.
2. The endpoint tries TCS, Leopards and Trax sequentially for any caller-supplied tracking number. There is no rate limit or order ownership check in the inspected controller, creating a tracking-number enumeration/privacy risk and avoidable courier API consumption.
3. PostEx and Rider are absent from the tracking resolver; only TCS, Leopards and Trax can be queried here.
4. Tracking widget accepts a tracking number from the URL query string and automatically submits it. Consider leakage through browser history, referrer headers, analytics and shared devices.
5. Courier provider responses are reduced to a single current status event. The full timeline, proof-of-delivery details, delivery attempt history and carrier-specific error state are not exposed.

#### Courier webhooks and shipment state integrity

1. Webhook routes are publicly callable by design, relying on provider signature verification inside the handler. Invalid signatures are silently acknowledged with HTTP 200; this hides attacks/configuration failures from provider retries and operator monitoring.
2. The handler has no visible event ID/replay-deduplication store. Replayed signed events can repeatedly update the WooCommerce order and trigger downstream hooks.
3. There is no explicit allowed-state transition matrix or event timestamp ordering. A late/replayed “in transit” event could regress an order after “delivered” or “returned”.
4. The order lookup scans postmeta by tracking number and carrier but does not use a dedicated indexed mapping table. Duplicate tracking numbers or reused carrier numbers could associate an event with the wrong order.
5. Webhook handler only covers TCS, Leopards and Trax. PostEx/Rider callback verification and state updates are not implemented in this route.
6. The circuit breaker returns `success=true` with a synthetic `PENDING-<order>` tracking value when every courier fails. Callers must treat `pending` as unbooked and must not show a real tracking number or dispatch-ready state. The scheduled retry has no visible bounded retry/backoff/dead-letter policy or operator resolution screen.
7. Circuit breaker returns immediately when a courier responds with a non-success result instead of trying the next courier; only thrown exceptions trigger failover. This may prevent failover on ordinary provider rejection/timeout responses represented as unsuccessful DTOs.

#### Database schema, installation and migration

1. The database installer creates OTP, logistics, chat, city mapping, affiliate and inventory tables and seeds city mappings. The inventory/affiliate schema needs a documented ERD, foreign-key/cleanup policy, unique constraints, transaction boundaries and data migration tests.
2. Main installer updates a single `pkstore_db_version` option to `2.0.0`; no per-module migration history, incremental upgrade runner or rollback strategy was demonstrated.
3. The installer relies on WordPress `dbDelta()` and does not check/report every SQL result. A failed table/column migration may still leave the DB version marked current, making subsequent upgrades skip repair.
4. City seeding exits when any mapping row exists; a partially seeded table will not be completed on later runs. City/courier IDs also need periodic reconciliation with provider master data.
5. Database tables use a mixture of IDs and no foreign-key constraints. Deleting WooCommerce products/users/orders can leave orphaned inventory, commission, payout, OTP or logistics records unless explicit cleanup hooks and retention policies are added.
6. No backup restore or schema-upgrade test evidence exists. The advertised monthly backup commitment is an operational hosting obligation and is not implemented/proven by these installers.


### Focused source pass — theme/plugin lifecycle, dependency loading and data governance

**Review pass recorded (16 Nov 2026):** Rechecked the current `main` tree, theme entry point, bootstrapper, bundled AI plugin entry point, feature manager, license manager and database installers. Static source review only; no WordPress runtime or syntax tests were executed.

#### Activation, upgrades and version identity

1. Theme database installation is attached to `after_switch_theme`. The inspected `functions.php` does not register a general upgrade runner on normal requests or a version-aware migration check. A site that updates the theme ZIP without switching themes may not run newly required schema migrations.
2. Main installer unconditionally writes `pkstore_db_version = 2.0.0` after calling the table installers. It does not check each `dbDelta()`/insert result before advancing the version. This reinforces the risk that a partially failed install/upgrade is recorded as complete.
3. Version identity is inconsistent: `functions.php` defines `PKSTORE_VERSION = 1.0.1`, `style.css` advertises theme version `3.2.1`, and `README.txt` also identifies the package as v1.0.1. The license server receives `PKSTORE_VERSION`, so it may see a different version from the one WordPress update tooling/admin reports.
4. Bundled AI plugin is version `1.1.0`, but it is only present as a source folder in this theme repository. Theme activation does not copy/install/activate it; the admin notice points to generic `plugin-install.php`, not a verified package installation/update path.
5. The bundled plugin registers an activation hook to seed default options, but no deactivation or uninstall hook/file is present in the repository tree. There is no documented policy for retaining or erasing its API key, chat/session data, settings or scheduled work on plugin removal.
6. No theme `uninstall.php`, plugin uninstall handler, migration test suite, or documented rollback path was found in the repository tree. Theme switching should not delete client data, but a supported uninstall/data-export/retention policy is also not demonstrated.

#### Bootstrap and feature/licensing enforcement

1. `ThemeBootstrapper::boot()` registers a `pkstore_require_feature` callback that calls `$this->container->make(...)`, but the class constructor stores the container as `$this->c` and declares no `$container` property. When this hook fires, the callback attempts to access an undefined property and cannot reliably obtain the license manager. This is a concrete bootstrap defect in the feature/licensing enforcement hook.
2. The bootstrapper registers `ShippingRegistry` unconditionally, while feature flags include individual courier entitlements. The review still does not establish consistent per-courier entitlement checks at every booking, tracking, webhook, admin and scheduled-job entry point.
3. `FeatureManager` loads local `pkstore_features` options and exposes local enable/disable/save operations. This remains a separate local flag layer, not a signed entitlement source from the Junction central owner panel. The local admin feature page also remains writable by `manage_options`, contrary to the owner-confirmed platform-owner-only package control.
4. Theme feature registration is conditional for some modules, but the registered courier retry callback, tracking controller, custom order statuses and other shared hooks need explicit authorization/entitlement checks at the operation boundary. Hiding a menu or not registering a widget is not sufficient enforcement.
5. The license manager uses a seven-day offline grace period for license-server reachability. This is distinct from the owner-confirmed seven-day unpaid-invoice grace period; the two clocks, reasons, customer messaging and suspension/recovery paths must not be conflated.
6. The client license manager calls `https://license.junctionsystems.pk/api/validate`, but the central service is not part of this repository. Activation, signed entitlement payloads, expiry, package changes, revocation, invoice/payment state and remote recovery therefore remain unverified.

#### Data governance, privacy and operational retention

1. The reviewed source tree contains chat history, OTP attempts, courier request/response logs, affiliate payout and inventory records, but no WordPress personal-data exporter/eraser hooks or documented retention/expiry cleanup job were found. Confirm applicable data subject export/deletion requirements and retention exceptions before production use.
2. The chat-history table stores message content and session UUID; the reviewed installer defines no expiry/cleanup policy. Owner-facing chat access, customer deletion requests, backup retention and staff access auditing need a single documented policy.
3. Logistics logs store request payloads as LONGTEXT and may include customer/order/shipping details or provider credentials depending on the logged request. Define field-level redaction, access permissions, retention and deletion before using these logs for long-term diagnostics.
4. OTP attempt records store phone, order ID and hashed code with timestamps; no cleanup schedule was found in the inspected installer/bootstrap lifecycle. Expired OTP rows should not become permanent customer data.
5. License key encryption uses AES-256-CBC with a randomly generated key stored in the same WordPress options database. CBC encryption has no authentication tag; tampering detection, key rotation, database-only compromise, backups and restore/migration across domains require a hardened secrets design.
6. `ThemeBootstrapper::applyBranding()` prints configured primary/secondary/accent colour values directly into a CSS style block and prints configured custom CSS. Validate/allowlist colour tokens and restrict custom CSS editing to a trusted owner capability; otherwise malformed or attacker-controlled settings can inject markup/script-capable CSS contexts.
7. The config export AJAX endpoint exports branding/configuration JSON after a `manage_options` check and nonce. Confirm the export schema excludes API keys, license material, webhook secrets, customer data and other credentials, and audit where exported files are stored/shared.

#### Test, build and packaging readiness

1. The current recursive `main` tree has no `composer.json`, `package.json`, PHPUnit/PHPCS/PHPStan configuration, test directory, or `.github/workflows` CI workflow. No repository-defined repeatable automated quality gate was found.
2. Theme and plugin source therefore lack demonstrated automated PHP syntax checks, JS module syntax checks, autoload/class-reference checks, dependency compatibility tests and release-package validation.
3. The tree has no visible dependency lockfile or reproducible asset build manifest. Confirm whether all shipped JavaScript/CSS is source-ready and whether any generated/vendor assets are expected to be produced outside this repository.
4. The next verification gate must run against a clean checkout of the exact release commit: PHP lint over every PHP file, JS syntax checks, static class/file reference scan, clean WordPress/WooCommerce install, theme switch/upgrade, plugin activation/deactivation/uninstall, backup restore and the owner-approved feature/role matrix. These tests have not yet been run.



### Focused source pass — payment/courier/feature entitlement enforcement

**Review pass recorded (16 Nov 2026):** Compared the 22 declared feature flags with theme bootstrap registration, WooCommerce gateway/shipping registries, REST controllers, admin screens and the license manager. Static source review only.

#### Payment feature flags and gateway wiring

1. `GatewayRegistry` unconditionally appends JazzCash and EasyPaisa classes to WooCommerce. It does not check `payment_jazzcash` or `payment_easypaisa`, and does not invoke the license manager. Disabling either package flag therefore does not reliably remove/block that gateway.
2. The registry references `PkStore\\Infrastructure\\Payment\\EasyPaisaGateway`, but the recursive `main` tree contains no corresponding PHP file/class. The service configuration imports the same missing class. This is a concrete autoload/runtime blocker when the class is resolved.
3. Foree Pay has a feature flag and a gateway implementation under `Payment/Foree/ForeePayGateway.php`, but `GatewayRegistry` never registers it. The package flag cannot enable the advertised payment method through the inspected normal registration path.
4. JazzCash gateway callback returns HTTP 200/“OK” when required fields are missing or the secure hash is invalid. That can suppress provider retries and hide rejected/invalid callback events from monitoring; log and alert securely while returning provider-appropriate failure semantics.
5. Foree callback trusts query-string `status=SUCCESS` and caller-supplied `order_id` to call `payment_complete()`. The inspected callback does not verify a provider signature, fetch authoritative transaction status server-to-server, validate amount/currency/session binding, or enforce idempotency. A forged callback could mark an order paid.
6. JazzCash callback verifies an HMAC but does not visibly compare callback amount/currency/order transaction reference against the WooCommerce order and initiated transaction record, nor does it show an idempotency guard for repeated successful callbacks. Verify provider canonical signing rules and reconcile exact transaction fields in sandbox.
7. JazzCash hardcodes transaction currency PKR and has no explicit store-currency guard. Confirm supported store currencies and fail closed for unsupported currency rather than sending a mismatched amount/currency.

#### Courier feature flags and operational paths

1. `ShippingRegistry` unconditionally registers TCS, Leopards and Trax shipping methods; it does not consult `courier_tcs`, `courier_leopards` or `courier_trax` or central license entitlements.
2. The normal courier circuit breaker is constructed with TCS, Leopards and Trax only. PostEx and Rider are bound in the service container but are not included in this booking path. Their declared feature flags do not make them operationally available through the inspected default checkout/auto-booking workflow.
3. The tracking controller and tracking sync cron are registered as core/unconditional paths. Provider-specific entitlement checks and licensed access checks are not established at the tracking lookup, cron, retry and webhook boundaries.
4. Courier webhook handler is registered unconditionally, while the WhatsApp webhook is registered only when its integration flag is enabled. The courier path must verify both the exact courier entitlement and provider authentication for each incoming event.
5. Digital-only checkout removes COD, but payment gateway availability and courier method availability are separate concerns; verify that disabled gateways/couriers are removed server-side and cannot be invoked through direct callbacks/API paths.

#### Feature enforcement architecture

1. A source-wide search of the reviewed core wiring shows the `pkstore_require_feature` hook being registered in the bootstrapper, but the feature modules/controllers inspected do not consistently call this hook or `LicenseManager::requireFeature()` at their sensitive entry points. The existence of the hook does not establish enforcement.
2. The current license manager returns feature names from a remote cached payload, while the local `FeatureManager` separately loads editable `pkstore_features` options. No verified single authoritative entitlement resolver was found that intersects central license entitlements with local operational flags and fails closed on stale/invalid sync.
3. Some flags are currently misleading or non-operative in the inspected wiring: `payment_foree` does not register Foree; `courier_postex` and `courier_rider` are not in the active circuit breaker; individual courier/payment flags are not applied in their registries; `multi_location` enforcement was not established in POS/inventory resource access; `barcode_labels` page registration was previously found without its flag check.
4. Admin capability checks (`manage_options`, `manage_woocommerce`, `edit_posts`) are local WordPress permissions, not proof of a Junction platform-owner identity. Central entitlement changes must be delivered through authenticated, signed, replay-resistant server-side configuration, not by trusting local admin form values.
5. The platform must distinguish three states for each feature: package entitlement (central owner decision), installation activation/configuration (credentials and setup complete), and runtime health (provider reachable/working). A feature being licensed does not mean configured or healthy; a local toggle must not grant a feature absent a valid central entitlement.

#### Required verification before any implementation

- Build a canonical matrix mapping every advertised package feature to its feature flag, class, registration path, REST/AJAX/admin/cron/webhook entry points, external credentials, dependencies, license check and runtime health indicator.
- Test both directions for every feature: disabled must fail closed on UI and direct server requests; enabled but unconfigured must show actionable setup state without claiming operational success.
- Payment gateway sandbox tests must cover valid/invalid/duplicate callbacks, wrong order/amount/currency/session, replay, timeout and provider-side pending/failed states.
- Courier tests must cover disabled provider direct booking/webhook/tracking, unsuccessful DTO failover, timeout, duplicate and out-of-order webhooks, pending vs booked state and operator retry.


### Latest pass — feature catalogue, package-tier mapping and admin enforcement (2026-11-16)

#### Confirmed source findings

- The source defines 22 local feature flags in `src/Core/Feature/FeatureManager.php`. They are loaded from the locally editable WordPress option `pkstore_features`; absent values fall back to defaults, several of which are enabled by default. `FeatureSettingsController` lets any local user with `manage_options` save all flags. This is not the approved Junction platform-owner-only package entitlement model.
- A separate `LicenseFeatures::ALL/BY_TIER` catalogue defines 24 different feature slugs. Its names do not map one-to-one to the 22 `FeatureManager` keys (examples: `jazzcash_payment` vs `payment_jazzcash`, `dukaan_ai` vs `ai_chat`, `junction_pos` vs `pos`, `inventory_management` vs `inventory`, `multi_location_inventory` vs `multi_location`). No verified translation/normalization layer was found in the inspected code. Therefore license feature names cannot be assumed to gate the actual local feature flags correctly.
- Package-tier mismatch against the supplied marketing commitments:
  - Basic marketing includes POS, inventory, Dukaan AI, WhatsApp Business API, QuickBooks/Daraz sync and white-label; source `LicenseFeatures::BY_TIER['basic']` omits POS/inventory/WhatsApp/QuickBooks/Daraz and uses separate `white_label`/ `basic_reports` slugs.
  - Standard marketing includes WhatsApp Business API and QuickBooks/Daraz sync; source Standard list omits WhatsApp/QuickBooks/Daraz, and adds GA4/Meta/Mailchimp/POS/inventory/barcode.
  - Enterprise marketing includes all Standard features plus PostEx/Rider, WhatsApp automation, QuickBooks, Daraz, Klaviyo, multi-location, Foree. Source Enterprise is `ALL`, but because the catalogue's slugs differ from FeatureManager and tier definitions do not match Basic/Standard marketing, this does not prove actual entitlements.
  - Marketing feature repetition across tiers remains authoritative as advertised; do not silently remove or tier-shift features to match code.
- `LicenseFeatures::forTier()` silently maps any unknown tier to Basic. This should fail closed or return an explicit invalid-tier error rather than granting a fallback feature list, subject to checking all call sites.
- `GatewayRegistry` registers JazzCash and EasyPaisa unconditionally; it does not receive/use FeatureManager despite bootstrap constructing it with two arguments. Foree is not registered there. This is a further confirmation that package feature flags do not govern gateway availability.
- `ShippingRegistry` registers TCS/Leopards/Trax unconditionally. It also schedules booking/tracking paths without an evident central entitlement gate at those entry points.
- `ThemeBootstrapper::boot()` calls `new GatewayRegistry($this->c, $this->fm)`, but the current constructor accepts only the container. Confirm runtime compatibility and remove stale/mismatched constructor wiring in a separately approved fix.
- Inventory admin controller registers Inventory, POS, Stock Log and Purchase Orders together whenever the inventory module controller is registered. The POS menu/page is therefore coupled to the inventory flag at bootstrap, even though POS has a separate flag. Conversely, the page handlers check only `manage_woocommerce`, not a central license entitlement or per-feature entitlement.
- Affiliate admin controller is registered unconditionally in admin bootstrap, even when the affiliate front-end controller/hooks are feature-disabled. Its pages check only `manage_woocommerce`; this can expose orphaned management pages while the feature is off.
- Barcode Labels admin page is separately registered unconditionally and checks only `manage_woocommerce`; it does not check `barcode_labels` or central license entitlement.
- Setup Wizard currently exposes General, JazzCash, EasyPaisa, TCS, Leopards, Trax, SMS/OTP and AI tabs. No equivalent setup tabs were found for Foree, PostEx, Rider, WhatsApp Business API, QuickBooks, Daraz, Mailchimp, Klaviyo, GA4, Meta CAPI or multi-location. AI tab branches to the bundled Groq plugin if active, otherwise presents a Gemini API key setup, despite the separately observed OpenAI bridge/provider wiring. This remains an unresolved, conflicting AI setup path.
- `LicenseAdminUI::renderTab()` prints the full decrypted license key in the admin page to users with access to that page. License keys should be masked by default and only revealed through an explicit, audited owner-only action if truly required.
- The Setup Wizard's License tab URL is referenced by LicenseAdminUI, but the wizard's declared tabs omit a `license` tab. The license screen integration therefore needs runtime verification; the notice can link to a tab that renders “No settings for this tab” unless an additional registration/conditional path supplies it.

#### Consequence

The advertised package matrix, license-tier catalogue, local feature flags, bootstrap registration and actual endpoint authorization are five separate layers with no demonstrated single source of truth. A client could be promised a feature that the license catalogue denies, or see a feature enabled locally that is not centrally entitled; a disabled feature may still have registered admin/gateway/shipping entry points. This is a release-blocking architecture and commercial-consistency gap.

#### Required next checks

- Generate a canonical, owner-approved mapping from every marketed package feature to one stable entitlement ID and map that ID to implementation flags, dependency rules and all entry points.
- Review every call site of `LicenseFeatures::forTier()`, `hasFeature()`, `requireFeature()`, `FeatureManager::isEnabled()` and the `pkstore_require_feature` hook; establish actual execution coverage, not merely declarations.
- Verify whether `LicenseAdminUI` is registered and how the License tab is rendered; inspect the complete remainder of SetupWizard and theme bootstrap wiring.
- Test each package in a clean installation: UI, direct REST/AJAX, checkout gateway/shipping availability, cron, webhook, admin page, and downgrade/upgrade transitions.
- Confirm what should happen when POS is entitled but inventory is not, and when a package lists the same capability in multiple tiers. Preserve existing data on downgrade.


### Latest pass — REST/AJAX, POS permissions, tracking and webhook execution paths (2026-11-16)

#### Confirmed source findings

- `ThemeBootstrapper::boot()` registers `TrackingController` unconditionally. `TrackingController` exposes public-nonce-authenticated POST `/pkstore/v1/tracking/status`; it accepts a caller-supplied tracking number and queries TCS, Leopards and Trax only. No order/customer ownership proof or request-level courier entitlement check is present in this controller. PostEx/Rider are not in this tracking lookup path.
- `ThemeBootstrapper::boot()` registers `ShippingWebhookHandler` unconditionally. It exposes public POST webhook routes for TCS, Leopards and Trax. The handler verifies provider signatures, but invalid signatures, missing tracking numbers, unknown tracking numbers and missing orders all return HTTP 200 `{"status":"ok"}`. This can suppress provider retries and makes failed webhook delivery difficult to monitor. Entitlement checks for each courier webhook are not visible at this boundary.
- The webhook handler updates WooCommerce order status directly from mapped provider status. The inspected handler has only a narrow delivered-status guard; no persisted event-id deduplication, replay store, provider event timestamp ordering or general legal-transition matrix is visible. A late event may therefore regress a newer order state. The database lookup by tracking postmeta is not visibly indexed or constrained to one order.
- `POSController` routes use `current_user_can('manage_woocommerce') || current_user_can('edit_posts')`. This is broader than a dedicated cashier role. `closeSession()` loads a caller-supplied session ID and closes it without checking that the current user owns the session or has manager override. `listOrders()` accepts caller-supplied session ID without checking session ownership. `currentSession()` accepts caller-supplied location ID without checking user's location assignment. These are resource-level authorization gaps, not just menu visibility issues.
- POS sale accepts client-supplied session/location IDs and forwards them to the use case. Revalidate session open state, cashier ownership, location assignment and price/discount authorization inside the use case/service layer; never rely only on UI controls.
- POS sync endpoint triggers the sync queue for any user passing the broad route permission. Queue synchronization should be restricted to authorized cashier/manager or trusted background worker, with location/session scope and idempotency.
- POS WhatsApp receipt endpoint accepts caller-supplied phone, order reference, item names, quantities and total; it builds a `wa.me` link without loading and verifying the authoritative POS order. It does not send through WhatsApp Business API. The response must not be represented as an API-delivered message.
- Inventory REST routes use `manage_woocommerce` permission callbacks. The inspected controller does not apply Junction central license entitlement or multi-location assignment checks at each route. Inventory admin pages likewise use only `manage_woocommerce`; POS page is grouped in the same admin controller as inventory and is gated at bootstrap by the inventory flag, while REST POS controller is separately gated by the POS flag.
- Inventory endpoints expose cost price, sale price and stock quantities to all users with `manage_woocommerce`. Confirm whether all cashier/staff roles should see cost prices; apply field-level permissions and custom staff roles.
- Affiliate REST routes: registration/dashboard/product submission require login; public product listing is intentionally public. The affiliate dashboard returns the full payout account value in the response. Confirm masking, account-change verification, and whether payout destination can be changed only through verified workflow. Public product listing includes affiliate redirect URLs; validate destinations and moderate content.
- Affiliate admin controller is registered unconditionally in admin bootstrap; pages are protected only by `manage_woocommerce`, even if affiliate feature is disabled. Apply feature and central entitlement checks at menu, page callback, REST routes, redirect/tracking hooks and payout actions.
- COD OTP routes are conditionally registered by the local `cod_otp` flag, but their permission callback only verifies a public WordPress nonce. As previously recorded, supplied order ID/phone are not bound to the actual WooCommerce order/customer in the controller, and success text overstates that a COD order is confirmed. Server-side checkout validation must consume a durable verified-order proof bound to actual order, billing phone, checkout session and COD method.
- `GatewayRegistry` registers JazzCash and EasyPaisa without checking their individual local flags or license entitlements. `ShippingRegistry` registers TCS/Leopards/Trax unconditionally and registers payment-complete auto-booking/tracking cron hooks without checking individual courier entitlement/configuration. Digital-only COD removal is separate from payment gateway entitlement enforcement.
- `FeatureSettingsController` gives every local `manage_options` user the ability to change all 22 local feature flags. This directly conflicts with the approved requirement that only the Junction platform owner controls package entitlements. Store owners may configure business operations only within centrally entitled capabilities.
- Theme bootstrap constructs `GatewayRegistry($this->c, $this->fm)`, but the current `GatewayRegistry` constructor accepts only the service container. PHP may tolerate surplus user-function arguments, so this is not asserted as a fatal error; it is stale/mismatched wiring and means the feature manager argument is ignored.

#### Security/architecture consequence

A user with a broad WordPress capability can reach operational endpoints without the custom cashier/location/session ownership model. Public nonce-protected endpoints are not customer identity proof. Courier callbacks can mutate orders while invalid callbacks are acknowledged as successful. Local feature flags are not reliable package enforcement, and courier/gateway registrations are not consistently gated.

#### Required verification

- Build a route inventory for every REST/AJAX/admin-post action, cron hook, WooCommerce hook and webhook; record capability, nonce/signature, license entitlement, feature flag, resource ownership, idempotency and audit logging.
- Create custom roles (owner, manager, cashier, stock clerk, support) and test allowed/denied actions, cross-location access, cross-session access and direct REST requests.
- Bind COD OTP proof to actual order/customer/phone/payment method and enforce it server-side during checkout/order creation; test guest and Blocks checkout.
- Courier webhook tests: invalid signature should return a meaningful non-2xx where provider retries are supported; test replay, duplicate event, stale event, illegal status transition, unknown tracking number and provider downtime; log a traceable outcome without leaking secrets.
- Tracking tests: order ownership verification or high-entropy tracking access token, rate limiting, no enumeration, only entitled/configured couriers, safe handling of PII and tracking URLs.
- POS tests: user cannot close another cashier's session, list another session's orders, sell against another location, sync another location, change unauthorized prices/discounts or forge receipt totals.
- Ensure feature entitlement checks happen inside application/use-case boundaries as well as UI and route registration, and re-check entitlement on queued jobs/webhooks where appropriate.


### Latest pass — WooCommerce hooks, scheduled sync and marketing integration execution (2026-11-16)

#### Confirmed source findings

- `InventoryHooks::register()` calls stock deduction/sync on `woocommerce_payment_complete`, `woocommerce_order_status_completed` and `woocommerce_order_status_processing`. The same order can enter more than one hook. No order-level idempotency marker is visible in this hook class; previously reviewed stock use case/repository path also lacked a demonstrated per-order idempotency guard. A single sale may therefore attempt repeated deductions. The hourly sync is also scheduled without visible cleanup/unschedule handling on feature disable or theme switch.
- `AffiliateHooks::register()` records commissions on both payment complete and order completed. Its `trackRef()` source contains `$_GET['ref']??'''` (extra quote) which appears to be a PHP parse error. This is source-level syntax evidence, but no PHP lint was run in this pass. Commission path still needs idempotency/unique DB constraint and refund/chargeback handling.
- `FacebookPixelService::register()` contains callback array `[\\$this,'injectPixel']`; the escaped dollar sign appears to be invalid PHP syntax. PHP lint required before calling it a confirmed parse failure. If the file fails to load, enabling Facebook Pixel can break bootstrap/runtime.
- GA4 `injectViewCart()` emits `value:<cart total>'` with a stray quote before `,items`, producing malformed JavaScript. GA4 purchase is fired both client-side on order-received and server-side on payment-complete; no shared event_id/dedup mechanism is visible. Client purchase flag is written before confirming browser event delivery, suppressing retry if delivery fails.
- Facebook Pixel purchase is emitted both browser-side and server-side; no shared event_id/fbc/fbp matching is visible. Browser purchase flag is also written before confirming event delivery. Consent gating for analytics pixels/CAPI was not found in the inspected service.
- Mailchimp `onOrderPaid()` is attached to both `woocommerce_payment_complete` and `woocommerce_checkout_order_processed`. The latter occurs during checkout order processing, not proof of payment; the method also calls `subscribeCustomer()` with `status_if_new='subscribed'` without visible explicit marketing consent check. This can subscribe customers who did not consent and may sync unpaid orders. Its `request()` calls concatenate HTTP method and endpoint (e.g. `'PUT'.'/lists/...'`) and pass the body in the endpoint argument, inconsistent with the method signature `request(string $method,string $endpoint,array $body=[])`. This is a concrete request-construction defect requiring tests/fix.
- Klaviyo `onOrderPaid()` calls `subscribeToList()` on every payment-complete order without a visible marketing consent check, then tracks the purchase. The service's event API calls similarly concatenate method and endpoint into one argument (e.g. `'POST'.'/events'`) and omit the endpoint argument; request construction does not match its declared signature. Klaviyo API version, payload contract and consent status need sandbox verification.
- WhatsApp hooks register order confirmation/shipping/delivery and low-stock alerts. The webhook route is POST-only, while `handleWebhook()` contains a GET challenge-verification branch; that branch is unreachable through this route. Incoming webhook POST messages are only logged; no signature verification, deduplication, message parsing, customer response or human handoff is implemented. Raw webhook payloads may contain personal data and should not be logged unredacted. WhatsApp order messages hardcode Urdu/Roman Urdu and shipping estimate 2–3 business days; these conflict with the approved store-configured facts/language/script rules unless made configurable and grounded.
- QuickBooks creates sales receipts using fixed ItemRef `1`, CustomerRef `1` and currency `PKR`. It does not visibly map WooCommerce products/customers, tax, shipping, discounts or store currency, and it only syncs paid orders. Token refresh token is stored via SettingsManager, but concurrent refresh, invalid_grant recovery, API response errors and operator reconciliation are not established.
- Daraz `pushProductFeed()` fetches only 100 published products, uses Generic brand and placeholder package dimensions/weight, and manually builds XML without escaping SellerSku/product data. `pullOrders()` creates a WooCommerce order but does not create order line items, set totals, payment/shipping/tax or verify order state. Hourly sync is scheduled without visible idempotent job ledger, pagination, webhook/retry or operator reconciliation. Product update hook triggers a full feed push for each product update.
- GA4, Meta CAPI, Mailchimp, Klaviyo and QuickBooks payloads hardcode PKR in inspected paths. This must be reconciled with store currency and accounting/tax settings before marketing commitments.
- `WhatsAppHooks` has an order-shipped callback for status `wc-shipped`, but actual custom order status registration and status transition mapping must be verified; it reads `_pkstore_tracking_number` and `_pkstore_courier`, while other courier flows previously inspected store `_tracking_*` and `_booked_courier` keys. Meta key mismatch may prevent shipping updates.

#### Consequence

Several integrations have declared registration classes but incomplete/incorrect request construction, missing consent controls, hard-coded business facts/currency, missing idempotency and incomplete order data mapping. Presence of an integration class or settings flag is not proof of a working production integration.

#### Required verification

- Run PHP lint over every tracked PHP file and JS syntax checks on all built assets; do not rely on visual inspection for suspected parse errors.
- Run provider sandbox contract tests for Mailchimp, Klaviyo, QuickBooks, Daraz, WhatsApp, GA4 and Meta; verify request method, URL, headers, payload, auth refresh, HTTP status and response parsing.
- Add explicit, auditable marketing consent capture and pass consent state to Mailchimp/Klaviyo; distinguish transactional WhatsApp from marketing messaging and apply opt-in/template policy.
- Use one idempotent integration event/outbox per order+provider+event, with retry/backoff/dead-letter, event IDs, deduplication and admin status/replay tools.
- Correct GA4/Meta browser-server purchase dedup and persist delivery state only after accepted response; account for consent mode and store currency.
- Map QuickBooks customers/items and full accounting values; map complete Daraz order lines and exact product variants; paginate product/order feeds and use safe XML serialization.
- Align WhatsApp route methods with Meta verification contract, validate webhook signatures, process incoming messages, deduplicate events, redact logs, and connect requested AI-to-human handoff with verified store support contacts.
- Align metadata keys across courier booking, tracking, and WhatsApp notifications; verify custom order status registration and transition events.


### Latest pass — Dukaan AI request path and WooCommerce catalogue grounding (2026-11-16)

#### Confirmed source findings

- Theme AI REST routes `/pkstore/v1/chat` and `/chat/stream` require a public WordPress nonce plus client-supplied UUID. The rate limit key is derived only from the UUID; a visitor can generate fresh UUIDs and bypass the per-UUID limit. It is not a robust per-IP/device/account limit, and transient read-modify-write is not atomic.
- `HandleAiChatUseCase` passes model ID `gemini-1.5-flash` to the configured `AiProviderInterface`. The service container previously inspected binds this interface to `OpenAiBridge`, which forwards the supplied model ID to OpenAI's chat completions endpoint. Therefore the active theme path attempts to call OpenAI using a Gemini model ID, while the Gemini bridge is present but not selected. This is a confirmed provider/model configuration mismatch in source wiring.
- Setup Wizard AI tab offers Gemini API key configuration, but the active OpenAI bridge reads `OPENAI_API_KEY`; the bundled plugin uses a separate Groq API key/model. There are three conflicting AI provider configuration paths and no single clear platform-owner-controlled provider/model setup flow.
- `ProductContextService::MAX=5`; it resolves at most five products by keyword or category. `ContextSelector` extracts only up to five tokens, has a small English/Roman Urdu stop-word list and fixed category dictionary. `WcProductRepository::findByKeywords()` searches only product title and excerpt using SQL LIKE/OR; it does not search SKU, long description, attributes, brand, synonyms, typo variants or all catalogue pages. Category search is also capped. This cannot satisfy full-catalogue discovery.
- The repository maps a WooCommerce product/variation object to context using SKU, name, regular price, optional sale price, stock status/quantity, short description, category, attributes and brand. The AI prompt does not provide a verified deterministic rule for using sale price as the customer payable price. Current regular price can be quoted instead of active sale price unless model behaviour happens to choose correctly; price should be normalized to one authoritative current payable price plus explicit regular/discount fields.
- Variable products are not resolved into exact variation records by the context service. Parent attributes may be shown, but requested size/colour/variation-specific SKU, price, stock and purchasability are not proven. No variant-selection workflow is visible in this path.
- Store metadata hardcodes PKR, COD available, all major Pakistan cities, 2–5 business day delivery, instant digital email delivery and a fixed 7-day defective-item return policy. These conflict with the approved per-store configurable facts and verified policy rules. Metadata also returns empty helpline/WhatsApp if unset; no safe configured-support/handoff state is established here.
- Prompt persona says same language customer uses (Urdu or English), but does not explicitly distinguish Urdu script from Roman Urdu; user requirement is script-preserving responses. Persona is hardcoded to “PK Store”, not the actual configured store name. It also asserts COD and digital delivery policies even if actual store configuration differs.
- Product data absence is handled by a no-details fallback, which is directionally safe. However, when search returns only a partial five-product subset, the model may incorrectly infer that the rest of the catalogue does not contain the requested product. The system needs complete retrieval or a verified “catalogue search incomplete” state.
- `OpenAiBridge::streamChat()` and `GeminiBridge::streamChat()` collect the entire cURL response into a buffer, then parse/yield after the request completes. This is not real incremental streaming. The theme controller catches all streaming exceptions and emits only `[DONE]`, so the client receives no structured provider error or human-support handoff signal.
- The synchronous controller returns generic 503 on provider errors and has no WhatsApp handoff response. Prompt injection reply is hardcoded Urdu script even for Roman Urdu/English customers. Chat session UUID is client-generated and is not authenticated to a customer/order identity; UUID alone must not authorize order/customer data access.

#### Required verification

- Consolidate to one provider adapter/configuration model, platform-owner-controlled per-install settings and explicit model compatibility validation; test OpenAI/Gemini/Groq separately only if they are intentionally supported.
- Replace five-product prompt stuffing with complete paginated retrieval/ranking over the entire WooCommerce catalogue; support exact SKU, name, category, synonyms, typo tolerance, attributes, brand and variation lookup. Return authoritative current payable price, sale/regular price, exact variation stock and direct product URL.
- Make catalogue completeness explicit. If retrieval is partial or provider/context fails, state uncertainty and offer configured human support rather than claiming no product exists.
- Replace hardcoded store facts with verified store settings and integration-backed facts. Unknown policies/fees/delivery promises must be unknown, not defaulted.
- Preserve language and script: English→English, Urdu script→Urdu script, Roman Urdu→Roman Urdu. Add automated tests.
- Implement real incremental SSE forwarding with disconnect/timeout/error handling, structured error events, bounded buffers and observability; do not silently end the stream.
- Apply layered abuse controls (IP/device/account/session, atomic counters, bot controls), protect nonce/session, and ensure chat UUID cannot be used to read another customer's data.
- Add clear customer-requested human handoff with verified store WhatsApp/support details and minimum necessary conversation context.


### New owner-requested requirement — Pakistan FBR integration (2026-11-16)

- Owner explicitly requested that FBR (Federal Board of Revenue, Pakistan) integration be added as a required Junction Systems capability.
- Initial source-tree scan of current `main` (289 tracked paths) found no identifiable FBR/Federal Board of Revenue/tax-integration implementation file or obvious FBR-specific module. Treat as **not found / presumed missing pending full code-path verification**, not as a completed integration.
- Add FBR integration to the canonical product roadmap and package-entitlement matrix only after the owner approves exact scope and commercial tier placement. Do not silently assign it to Basic/Standard/Enterprise based on assumptions.
- Required audit/design scope: identify applicable Pakistan tax regime(s) and taxpayer eligibility; FBR/POS integration or Digital Invoicing API requirements; invoice/receipt registration and unique fiscal identifiers; QR/barcode or verifiable invoice data where mandated; real-time/offline queue behaviour; cancellation/credit-note/refund handling; tax rates/exemptions and customer tax identity; branch/location and terminal mapping; credentials/certificate lifecycle; API authentication/signature, idempotency, retries and reconciliation; audit trail, retention, backups and reporting; sandbox/certification and production onboarding.
- Architecture must support per-client/per-install credentials and tenant isolation, with Junction platform owner controlling package entitlement and deployment-level enablement, while authorized store roles manage permitted business/tax settings. Never share credentials or tax records between client installations.
- POS, WooCommerce online orders, refunds/returns, invoice generation and accounting exports must not produce duplicate or contradictory fiscal records. FBR acceptance/rejection/timeout must be represented truthfully; an unconfirmed submission must never be labelled fiscally registered.
- Do not hardcode tax rates, legal eligibility, invoice format or API endpoints. Verify current official FBR documentation and applicable rules during the dedicated FBR design pass before implementation.
- Package placement, whether all tiers or selected tiers, exact compliance scope, and whether integration is mandatory for all Pakistani clients remain owner decisions.


### Latest pass — checkout, payment callbacks and stock/order lifecycle (2026-11-16)

#### Confirmed source findings

- Checkout frontend `assets/js/modules/checkout.mjs` keeps COD verification only in in-memory browser state (`state.isVerified`). It blocks classic checkout form submission in JavaScript, but a direct HTTP checkout/Store API request bypasses this client-only state. The previously inspected COD OTP controller verifies an OTP attempt row, but does not bind durable proof to the actual created WooCommerce order, billing phone, checkout session and COD payment method. Server-side checkout/order validation is required.
- COD checkout initializes `state.orderId` from a meta tag's `data-order-id`; checkout is commonly creating a new order before submission, and the template previously inspected outputs an empty order ID. Guest/classic/Blocks checkout behaviour therefore requires integration testing; no trustworthy pre-order identity binding is established.
- `JazzCashGateway::process_payment()` creates a transaction reference from current timestamp plus last four order ID digits; collision/idempotency and persisted initiation record are not visible. It hardcodes PKR and amount conversion to integer minor units. Callback verifies HMAC but on missing integrity key/order reference or invalid hash returns HTTP 200 OK, hiding failures from monitoring/provider retries. Callback then trusts `pp_BillReference` to load order and calls `payment_complete()` on response code 000; matching callback amount, currency, transaction reference and prior initiated transaction against the WooCommerce order are not visibly verified. Duplicate/replay callback guard is not visible.
- `ForeePayGateway::process_payment()` stores the provider session ID but does not visibly persist an immutable payment initiation record with amount/currency/expiry/reference. `handleCallback()` reads caller-controlled GET parameters `session_id`, `status`, `order_id`; if `status==='SUCCESS'`, it calls `payment_complete($sessionId)` without verifying provider signature, server-to-server transaction status, session-to-order binding, amount, currency, merchant ID or idempotency. This is a critical payment-forgery risk. Foree is not currently registered in the inspected GatewayRegistry, but its callback action may be reachable if the class is instantiated/registered elsewhere; exact runtime route exposure needs verification.
- JazzCash and Foree gateways hardcode PKR, so non-PKR WooCommerce stores are not safely supported by these implementations. Enforce store currency compatibility or use authoritative currency/decimal minor-unit handling.
- `InventoryHooks::register()` calls `onSale()` on payment complete, processing and completed. `SyncWooCommerceStockUseCase::onWooCommerceSale()` deducts custom inventory for each event and passes order ID as movement reference, but no unique order/item movement guard is visible in the inspected use case/repository path. Repeated lifecycle hooks can deduct the same sale more than once. WooCommerce's own stock reduction is a separate mechanism; no single stock authority/reconciliation contract has been proven.
- Inventory sale processing uses `$item->get_product_id()` rather than variation ID. For variable products this identifies the parent product, not necessarily the exact variation-level stock record. Exact variation inventory mapping and aggregate parent stock behaviour are not established.
- `SyncWooCommerceStockUseCase::onWooCommerceSale()` catches `DomainException` and continues; the order payment/status remains successful while custom inventory deduction may fail. There is no demonstrated transaction/outbox/reconciliation status that makes this divergence visible to the operator.
- `AdjustStockUseCase::execute()` performs get quantity → mutate entity → update stock → insert movement → sync WooCommerce as separate operations without a DB transaction or atomic conditional update. Concurrent sales/adjustments can race; movement insert failure may leave stock changed without ledger, and stock update failure return values are not checked.
- `AdjustStockUseCase::setAbsolute()` has the same non-transactional stock/ledger sequence. Stock changes need atomic locking/transactions, validated deltas, immutable movement ledger and reconciliation.
- In inspected `InventoryHooks` and `SyncWooCommerceStockUseCase`, no refund, cancelled-order, failed-payment recovery, chargeback or returned-to-stock hooks were found. Define exactly when custom stock is reserved, deducted, released or restocked for unpaid/pending/failed/cancelled/refunded/partially refunded orders. Do not restock a physical item merely because refund was issued if goods have not been received/inspected.
- Hourly sync writes the sum of all custom location stock to WooCommerce product stock, but it only fetches first 500 inventory products (`findAll(500,0)`) and has no pagination. For more than 500 items, later products are not synchronized. The online location and multi-location allocation rules need to be explicit.
- Shipping webhook status can update order state and COD delivered metadata. It has no visible durable event idempotency/replay ledger or provider event-time ordering. It can update order status directly from provider status mapping; legal transition policy and COD remittance/payment reconciliation must be separate from delivery confirmation.
- Order tracking metadata keys differ between `OrderMetaRepository` (`_tracking_{$cc}`, `_tracking_courier`, `_booked_courier`) and WhatsApp shipping hooks previously inspected (`_pkstore_tracking_number`, `_pkstore_courier`). This can cause WhatsApp shipment/delivery notifications to miss successfully booked tracking records.

#### FBR dependency / fiscal record implication

- FBR integration must be designed across both POS and WooCommerce order lifecycles, not as a standalone settings toggle. Every eligible sale should have one fiscal submission identity and auditable state; duplicate WooCommerce hooks, POS offline retries, payment callbacks and courier callbacks must not create duplicate fiscal invoices.
- Refund/cancellation/credit-note handling, tax calculation and fiscal registration must remain linked to the source sale and verified return/payment state. A fiscal submission timeout is pending/unknown until reconciled; never mark it registered without official confirmation.

#### Required verification

- Payment gateway sandbox matrix: valid, invalid, duplicate, replay, wrong amount, wrong currency, wrong order, wrong merchant/session, expired session, pending, failed, timeout and delayed callback. Verify the provider server-side before payment_complete; persist idempotency key and transaction record.
- COD: classic checkout, guest checkout, WooCommerce Blocks/Store API, express checkout and direct API calls. Bind OTP verification server-side to checkout session/customer phone and actual COD order; client JS only improves UX.
- Stock: one order triggering payment_complete+processing+completed must deduct once; test variations, bundles, backorders, concurrent sales, multi-location, failed custom deduction, partial/full refund, cancellation before shipment, return received and stock restoration. Use transaction/atomic update and unique source-event key.
- Add stock reconciliation dashboard for WooCommerce stock vs location ledger; alert on divergence and never silently treat a failed deduction as normal.
- Paginate hourly stock sync and test >500 products; define whether WooCommerce stock is sum of locations or online-available allocation, with reserved stock/backorders rules.
- Align courier tracking metadata keys and test shipping/delivery notification after successful booking.
- Add FBR fiscal outbox/idempotency/reconciliation linked to order/POS receipt, with provider acceptance/rejection state, offline retry and credit-note/refund linkage.


### Latest pass — POS offline synchronization and courier booking integrity (2026-11-16)

#### Confirmed source findings

- `ProcessPOSSaleUseCase::execute()` checks that a session exists and is open, but the use case itself does not check that the current cashier owns the session or that the supplied location is assigned to that cashier. Controller permissions/resource checks are insufficient; enforce inside the use case.
- POS sale accepts caller-supplied `unit_price_pkr`, item discount and sale discount. It loads product master data but uses caller-supplied unit price when present; no role-based override reason, maximum discount policy, price floor or manager approval is visible. Quantity validation (positive integer/stock availability) and payment split reconciliation also need validation.
- POS order and order items are saved in separate DB operations without transaction. If item save or stock adjustment fails after order header insert, a partially persisted sale can remain.
- POS stock deduction catches `DomainException` and continues, so the sale is accepted while stock remains unchanged. This needs an explicit owner-approved policy; default safe handling should prevent a false successful sale or mark it as stock exception requiring manager resolution.
- POS session totals are updated after sale without a transaction covering order, items, stock ledger and session totals. A failure mid-way can create financial/session mismatch.
- `SyncPOSQueueUseCase::execute()` does not create a WooCommerce order, transmit the sale to an external server or call `ProcessPOSSaleUseCase`; it simply marks pending POS orders as synced. The result count therefore overstates synchronization. There is no remote payload, remote acknowledgement or idempotency reference.
- `WpPOSOrderRepository::findPending()` reads at most 50 rows, with no pagination/locking/claim state. Concurrent workers can process the same pending orders. `update()` changes only sync status and timestamp; it does not store WooCommerce order ID or external acknowledgement.
- POS offline order reference/record needs a durable idempotency key generated at terminal creation and retained through retries. On reconnect, the system must reconcile product, price, tax, payment and inventory changes since the sale was made; never silently reprice a completed customer sale.
- `BookShipmentUseCase::execute()` saves tracking metadata only when result is success and tracking number is not synthetic PENDING. But the underlying circuit breaker returns synthetic PENDING as `success=true`; retry handler then adds an order note for any success, which can describe a pending booking as “Courier booked”. Status semantics need explicit enum: booked, pending, rejected, failed, cancelled.
- `CourierCircuitBreaker::bookWithFailover()` tries the next courier only when provider throws an exception. If a provider returns a non-success `ShipmentResultDTO`, it returns immediately and does not fail over. It treats only thrown exceptions as circuit failures.
- If all couriers are open/fail, `optimistic()` returns `success=true` with tracking `PENDING-{orderId}`, schedules one Action Scheduler retry and emails admin. This is a false-success semantic and does not persist a durable booking job/outbox status, retry count, max attempts, last error, or operator resolution. Retry scheduling can also be unavailable when Action Scheduler is not installed.
- `BookShipmentUseCase::retryForOrder()` rebuilds the shipment DTO and rebooks without an explicit duplicate-booking guard or checking whether a real courier tracking number already exists. Multiple retries can create duplicate shipments.
- `BookShipmentUseCase::buildDto()` hardcodes country PK, weight default 0.5kg, empty dimensions and standard service. COD amount equals full order total; shipping discounts, partial payments, already-paid status, tax/declared value and provider-specific COD cap are not separately considered.
- PostEx class is a standalone API client with booking and COD remittance retrieval; it is not included in the inspected common `CourierProviderInterface` failover constructor path. No remittance scheduler/reconciliation UI was found in this pass.
- Rider class hardcodes same-day cities (Karachi, Lahore, Islamabad, Rawalpindi), pickup defaults and 1kg weight, logs full API response (may include PII), and does not expose tracking/webhook status integration in the inspected path.

#### FBR integration impact

- POS offline queue must not mark a sale “synced” until server-side order/fiscal reconciliation receives an explicit acknowledgement. FBR fiscal submission must be an idempotent, separately tracked step after authoritative sale data is accepted; an offline POS receipt should clearly distinguish local provisional receipt from officially registered fiscal invoice where applicable.
- POS tax amount/rate/taxpayer details must be snapshotted at sale time, not recalculated using changed current settings when the device reconnects. FBR rejection/timeout must remain visible and retryable without creating duplicate fiscal invoices.
- WooCommerce online orders and POS sales must share one canonical fiscal invoice/outbox model, with distinct source IDs and stable unique idempotency keys. Refunds/credit notes must reference the original fiscal invoice.
- Courier booking success is not proof of delivery or COD remittance; FBR sale reporting and tax liability must not be inferred from shipment status alone. Reconcile sale/payment/refund/tax independently.

#### Required verification

- POS transaction tests: fail between header/items/stock/session updates; prove rollback or durable exception workflow. Test two concurrent cashiers and offline duplicate retries.
- POS pricing/discount tests: tampered unit price, negative/zero quantity, excessive discount, unauthorized discount override, split payments not matching total, product disabled/deleted, changed tax config while offline.
- POS sync tests: >50 pending records, multiple workers, no network, duplicate retries, server rejection, missing product, idempotency, WooCommerce order creation and correct order items/totals/tax/payment status.
- Courier tests: unsuccessful DTO failover, timeout, duplicate retry, already-booked order, pending vs booked status, action scheduler unavailable, retry exhaustion and operator reconciliation.
- Implement durable POS/courier outbox state with idempotency, bounded exponential retry, dead-letter/manual resolution and traceable provider acknowledgements.
- For FBR, test offline provisional receipts, server acknowledgement, official invoice reference/QR or other mandated response, duplicate replay, API rejection, timeout reconciliation, return/refund/credit note and branch/terminal mapping against official FBR sandbox.


### Latest pass — database lifecycle, migration safety, deployment recovery and license boundary (2026-11-16)

#### Database installation / migrations

- `Installer::run()` is invoked from `after_switch_theme` and creates OTP, logistics, chat and city tables, then calls affiliate/inventory installers and unconditionally writes `pkstore_db_version=2.0.0`. No version-aware migration runner on normal application boot or plugin/theme update was found in the reviewed source.
- The top-level install sequence does not check `dbDelta()`/wpdb errors or verify required tables/indexes before setting the database version. A partial schema failure can be recorded as a successful 2.0.0 install.
- Theme DB setup runs on theme switch; this is not a reliable upgrade mechanism for a deployed customer who updates files without switching themes. Need explicit migration runner with ordered migrations, schema checksums/current version, retry-safe steps and admin-visible failure state.
- Inventory installer creates stock, movement, POS header/item, supplier and purchase-order tables. The reviewed schema uses indexes but no declared foreign-key constraints. Application-level integrity/cleanup and orphan detection need explicit handling.
- POS order header and line items are written separately; purchase order header/items and stock/movement workflows also need transaction boundaries. A DB transaction is required where all operations share the same transactional InnoDB connection; otherwise use durable workflow/outbox with compensating actions.
- POS schema has `wc_order_id` but inspected sync code never populates it. It has `sync_status` but no remote acknowledgement, last error, attempt count, retry time, claim/lease, or idempotency key. Existing pending queue cannot support reliable recovery/reconciliation.
- Affiliate commissions have unique index on order_id, which is a useful idempotency constraint; however refunds/partial refunds/chargebacks and commission reversal records are not represented in the inspected schema. Payout rows store payment account at payout time, but account change/audit/verification and payout reversal controls need review.
- OTP attempts and chat history tables include created/expiry timestamps but no demonstrated scheduled cleanup/retention enforcement in the reviewed installer/bootstrap paths. Logistics log stores full payload LONGTEXT and needs redaction, encryption/access control and retention expiry, especially for customer phone/address/API credentials.

#### Backup, restore and disaster recovery

- Current recursive `main` tree contains no identifiable backup/restore implementation, backup scheduler, restore workflow, disaster-recovery runbook or backup verification test. This is a source-tree observation, not proof the hosting provider has no backups.
- Marketing commitment advertises monthly backups. That must be distinguished from platform-level backup automation: scope (DB + uploads + theme/plugin config + secrets), retention, encryption, geographic separation, restore-point objective (RPO), recovery-time objective (RTO), restore testing, client isolation and backup failure alerts are not defined in this codebase.
- Require per-install backups and restore verification. A restore must preserve WooCommerce orders, POS records, stock movement ledger, FBR fiscal references, license binding and encrypted credentials consistently; it must not restore one subsystem to a different point in time from another.
- Secrets/config export must be reviewed before any backup/export feature is treated as safe. Current ThemeBootstrapper config export calls BrandingManager::exportConfig(); the exported schema has not yet been audited for API keys, license material, tax credentials or customer data.

#### License manager / central control boundary

- Local `LicenseManager` calls external endpoint `https://license.junctionsystems.pk/api/validate`; central service source is not in this repository and remains unaudited. No claim can be made yet that central dashboard, package billing, invoices, payment verification, deployment provisioning or entitlement push are implemented.
- Local manager caches license data for 24 hours and allows a seven-day offline grace. This is technical connectivity grace and must remain distinct from the separately owner-confirmed 7-day unpaid-invoice grace. Policy for revoked/suspended license while cache is still fresh, clock rollback, stale package downgrade and central entitlement push needs testing.
- The remote request HMAC key is derived from a random key stored in the same WordPress options database as the encrypted license key/cache. If the DB is compromised, both encrypted key and local HMAC secret are exposed together. Consider per-install signed response verification with a public-key signature, secure key rotation and no symmetric master secret stored alongside the data it protects.
- License cache encryption uses AES-256-CBC without an authentication tag; ciphertext tampering is not cryptographically authenticated. Use authenticated encryption (e.g. AES-GCM) or encrypt-then-MAC with key separation, and plan rotation/backup restore behaviour.
- `LicenseFeatures::forTier()` silently maps unknown tier names to Basic. Unknown/corrupt tier should fail closed with explicit error, not silently grant a fallback package.
- LicenseFeatures defines 24 slugs, FeatureManager defines a separate local flag catalogue with different slugs, and ThemeBootstrapper checks local FeatureManager flags. No authoritative mapping/intersection between signed central entitlements, local operational toggles and endpoint-level authorization has been demonstrated. Local editable flags cannot be the authority for paid feature rights.
- ThemeBootstrapper registers a `pkstore_require_feature` callback using `$this->container`, but constructor stores the service container as `$this->c`. This is an undefined-property reference and likely breaks the central license guard when invoked. The inspected feature bootstrapping also does not call `LicenseManager::requireFeature()` consistently at every sensitive route/job/provider boundary.
- LicenseManager imports `LicenseExpiredException`, `LicenseDomainMismatch`, `LicenseInvalidException` and `LicenseFeatureNotAvailable`; current recursive tree exposes only `LicenseException.php` under `src/License/Exception`. The absent exception files may cause runtime class-not-found errors when those paths execute. Must run class/autoload checks and confirm exact main tree before release.
- License admin UI previously observed displays full decrypted license key to admin users. Mask by default, allow controlled rotate/reveal only to platform owner, and audit every reveal/change. Store-level `manage_options` must not confer authority to change package entitlement.
- `LicenseManager::lockout()` writes a lockout reason/time, while exact enforcement points and safe recovery/re-activation behaviour need verification. Ensure billing suspension preserves the restricted billing/recovery area and never deletes customer business data.

#### FBR / fiscal continuity dependency

- FBR credentials, taxpayer registration, branch/POS terminal identifiers, invoice counters/official references and fiscal submission queue will become critical deployment data. They must be included in encrypted per-install backup and verified restore, but secrets must not appear in plaintext exports or general logs.
- Database migration design must include additive FBR schema migrations with rollback/forward-repair plan, unique fiscal idempotency constraints, source-order linkage, submission attempts/status, provider response reference, invoice snapshot and credit-note/refund linkage.
- License entitlements must gate FBR server-side across POS sale, WooCommerce checkout/order, refund/credit note, scheduled retries and manual resubmission; hiding the FBR settings menu is not enforcement.

#### Required verification / decisions

- Build and run automated PHP lint, class autoload/reference scan, DB schema install/upgrade tests from clean and prior versions, interrupted migration tests, rollback/forward-repair tests, and backup-restore drills. These tests have not been run in this audit pass.
- Confirm hosting backup provider/plan, frequency, retention, encryption, offsite copy, restore SLA and whether backup/restore is centrally monitored across all customer installations.
- Obtain read-only source/API documentation and authorized test access for the central license/billing service before claiming central management is complete.
- Define a canonical signed entitlement document and one shared resolver: central package entitlement ∩ platform-owner feature toggle ∩ deployment health/license status; fail closed on invalid signature/unknown feature/invalid tier, while applying documented safe offline grace.
- Define data retention/deletion for chat, OTP, logistics payloads, customer data, POS and fiscal records, subject to applicable legal retention requirements.


### Additional pass — configuration export, license key exposure and refund surface scan (2026-11-16)

- `BrandingManager::DEFAULTS` includes `client_license_key` in the ordinary `pkstore_branding` option. `saveAll()` treats it like ordinary branding text (sanitize only), and `exportConfig()` serializes the entire branding config. Therefore the config export can include a plaintext client license key. Remove secrets from branding config/export; store license material only in the dedicated encrypted license subsystem and redact all secrets from export by schema allowlist.
- `BrandingManager::importConfig()` writes supplied `branding` and `features` arrays directly to options without allowlist validation, schema/version validation, capability/nonce checks in the method itself, or filtering privileged entitlement fields. If exposed by a caller, import can overwrite feature flags or inject unexpected config. Treat import as privileged, validate every key/value, and never allow customer-side import to grant paid entitlements.
- Config export route is guarded by `manage_options` and nonce, but that is a WordPress local admin capability, not proof of Junction platform-owner authority. Export scope must exclude API keys, license keys, FBR credentials, payment/courier secrets, customer data and chat/order records.
- `LicenseAdminUI::renderTab()` displays full decrypted license key in the page. Mask it by default; reveal only via explicit platform-owner action with re-authentication/audit, if reveal is required at all.
- `GatewayRegistry::__construct()` accepts only one `ServiceContainer` argument, while `ThemeBootstrapper::boot()` instantiates it with both container and FeatureManager. PHP user-defined constructors may tolerate surplus positional arguments, so this is not asserted as a fatal error; however the registry ignores FeatureManager and registers JazzCash/EasyPaisa unconditionally. Foree is not registered here despite an implementation class and Enterprise marketing listing. EasyPaisa class file was not present in the recursive tree. Runtime gateway availability and feature gating are inconsistent.
- Recursive main-tree path scan returned no refund/return/backup/restore/migration/test/build-specific paths. This supports “no identifiable dedicated implementation found in this repository”; it does not prove WooCommerce core or hosting provider features are absent. In particular, no project-owned refund/credit-note workflow was found in the reviewed source tree, so FBR fiscal credit-note integration has no established internal workflow to attach to yet.
- The same tree scan found no tests directory, composer.json, package.json, PHPUnit/PHPCS/PHPStan config or GitHub Actions workflow. Automated validation/release gate is not evident in the repository. PHP lint, dependency manifest and reproducible build checks remain unrun.


### Latest pass — central license client and entitlement enforcement (2026-11-16)

#### Confirmed local-client behaviour

- LicenseManager::activate() accepts a key matching the JUNC format, sends an activate request to https://license.junctionsystems.pk/api/validate, then stores encrypted key and encrypted returned data cache. It does not itself validate a signed response schema, expected product ID, tier enum, feature slug allowlist, installation UUID or monotonic entitlement version before caching. Remote response is trusted based on TLS/HTTP result and JSON fields.
- remoteCall() sends action, plaintext license key, current hostname, product pk-store, PKSTORE_VERSION and HMAC-SHA256 signature. Signature key is generated locally in WP options and is not provisioned from an authenticated central registration flow in this repository. There is no installation UUID or deployment ID in the request; hostname is the principal deployment identity.
- Response HTTP handling only maps 403 to domain mismatch and 402 to expired. Other non-2xx codes are decoded and returned as an ordinary result; no explicit bounded retry/backoff, circuit breaker, request correlation ID, provider error classification or operator-visible health status is implemented in this client.
- The response is not visibly verified with a central public-key signature. A compromised local DB can alter encrypted cache only if encryption key is also obtained; because key and cache are in same options DB this is not a strong trust boundary. More importantly, no cryptographic server-signed entitlement proof exists for offline validation.
- getLicenseData() uses a 24-hour cached result and only contacts central server when cache expires. It does not poll for immediate package upgrade/downgrade/payment suspension or consume a central push/webhook/signed entitlement update. Therefore owner-confirmed automatic package propagation is not demonstrated by this local client.
- handleGrace() uses cached license data for seven days on any caught LicenseException; no distinction between network outage, invalid response, malformed payload, server 5xx or authentication/configuration failure is made in this catch path. A server-side revoked/suspended entitlement can remain usable until fresh validation/cache/grace rules resolve it.
- assertValid() checks hostname and expiry only. It does not validate an explicit status such as active/suspended/revoked, paid-through date, package version, entitlement revision, installation ID or server-issued grace cutoff. The local cache can therefore treat data as valid even if central service has since suspended the deployment, until revalidation.
- deactivate() calls remote endpoint but ignores whether remote deactivation succeeded; it deletes local key/cache/grace regardless. If network fails, a still-active server-side domain binding may remain while the local installation believes it is deactivated. Need a pending-deactivation state and retry/reconciliation.
- isLockedOut() checks a persistent lockout reason, but successful activation deletes only grace start; it does not clear pkstore_lockout_reason or pkstore_locked_at. A previous domain/grace lockout may continue showing hard lock even after reactivation unless another path clears it. Test and define safe recovery.
- License manager imports four exception classes not present in the current tree except base LicenseException.php; LicenseExpiredException, LicenseDomainMismatch, LicenseInvalidException, LicenseFeatureNotAvailable need class definitions/autoload verification. This is a likely runtime blocker, but PHP lint/autoload execution was not run.
- LicenseFeatures::forTier() returns Basic for every unknown tier; an invalid tier should reject/deny rather than map to a paid package.
- LicenseFeatures::BY_TIER includes Basic and Standard feature lists, with Enterprise all 24 slugs. The slugs do not align with FeatureManager names. No canonical mapping/translation exists in reviewed code.
- FeatureManager loads locally persisted pkstore_features, and its defaults enable many capabilities (including POS/inventory/barcode, gateways, AI, affiliate) before license is consulted. The local flag is not intersected with central entitlement.
- FeatureSettingsController::handleSave() lets any manage_options user save every local feature flag. This directly violates the owner-confirmed rule that only Junction platform owner may grant/revoke paid package entitlements. Local store administrator should be able to configure business settings, not grant themselves paid entitlements.
- ThemeBootstrapper::boot() defines the pkstore_require_feature action callback with undefined $this->container; actual property is $this->c. Even if corrected, reviewed feature controllers/jobs do not consistently call this hook or LicenseManager::requireFeature() at their own security boundary. A hook definition alone is not enforcement.
- ThemeBootstrapper conditionally registers some controllers using local flags only. Other integrations are also local-flag-only. GatewayRegistry registers JazzCash/EasyPaisa unconditionally; ShippingRegistry registers TCS/Leopards/Trax unconditionally; TrackingController and ShippingWebhookHandler are unconditional; admin controllers and barcode page are registered without a central license entitlement check.
- SetupWizard has an AI settings page that instructs store owner to obtain and enter their own Gemini key, while previous code tracing found the active theme AI provider binding is OpenAIBridge and expects OPENAI_API_KEY; bundled Groq plugin separately stores Groq key. This conflicts with the later owner direction that platform owner controls AI provider/key/model configuration. A single provisioning/ownership model and secure per-install secret delivery is required.
- SetupWizard's complete status previously inspected checks only JazzCash merchant credentials and TCS despite other required sections; setup completion cannot establish deployment readiness.
- No central service source, API contract, deployment ID lifecycle, signed package/entitlement schema, payment webhook, invoice record, suspension/re-activation state machine, remote feature-sync worker, or per-install health telemetry was present in this repository. The external central system remains unaudited.

#### Required target architecture

- Each deployed customer copy must register with a stable immutable installation/deployment ID independent of hostname; domain changes/transfers require audited owner workflow.
- Central service issues a signed entitlement document containing deployment ID, package/tier, enabled feature slugs, entitlement revision, active/suspended/revoked state, paid-through/expiry, issued-at, not-before, offline-valid-until and key ID. Client verifies signature using pinned public key; no shared signing secret in customer WP database.
- One canonical feature registry and explicit slug migration map should connect marketing package matrix, central entitlement, local operational toggle, bootstrap registration and every REST/AJAX/cron/webhook/use-case authorization. Effective access is central entitlement AND owner-controlled deployment toggle AND required operational dependencies. Local store admin cannot elevate entitlement.
- Entitlement sync should support immediate central push where available plus bounded polling fallback, revision monotonicity, signed cache, retry/backoff and visible last-sync/error status. Package downgrade must preserve customer data and disable only future feature actions; upgrade enables only after verified entitlement sync.
- Billing suspension is a distinct server state from technical offline grace. Per owner policy, unpaid invoice grace is 7 days; after it expires central service should issue signed suspended entitlement, and client should restrict storefront/operational use while retaining billing recovery/login. Do not reuse the local 7-day connectivity grace as payment grace.
- Reactivation only after central verified payment and signed active entitlement sync. Local client must not infer payment from user screenshots or manual client flags. Retry safely and preserve data.
- Deactivation must be a durable state machine: pending request, central acknowledgement, local clear only after ack or documented recovery path; no silent loss of local credentials while server binding remains active.
- Audit every platform-owner change to package, entitlements, key rotation, license transfer, suspension/reactivation, and every local privileged operation. Store owner/staff should see status but cannot alter commercial rights.
- AI provider credentials should be provisioned per deployment with least privilege and encrypted storage; ownership/BYO key billing policy remains unresolved and must be confirmed before implementation.

#### Required central-system evidence

- Obtain central license/billing service repository or read-only API specification and authorized sandbox account. Audit database schema, auth, license key issuance, customer/deployment mapping, package feature matrix, invoice generation, gateway verification, 7-day overdue scheduler, suspension/restore state machine, signed entitlement generation, deployment sync retries, audit logs and admin RBAC.
- Test multi-install isolation, same key concurrent activation, hostname migration, domain takeover, revoked key, package upgrade/downgrade, stale cache, 7-day offline, unpaid overdue, verified payment, duplicate gateway webhook, delayed webhook, failed sync, clock skew, backup restore and deactivation retry.


### Cross-repository check — possible central platform source (2026-11-16)

- Checked the connected GitHub repositories named Junction/Junction Systems. The only directly matching product source remains haseeb1984/Junction-Systems. The other visible repository junctiontools is a separate online-tools website project, not the Junction Systems license/billing control plane. JunctionLMS is a separate LMS project. No central licensing/billing source was identified in these repositories.
- This does not prove the central panel does not exist; it may be hosted outside the connected repositories or under another repository/account. Its implementation remains unverified until its exact repository/API is provided.


### Licensing implementation classification — refined after source reinspection (2026-11-16)

The owner clarified that some licensing code may already exist but the complete central licensing platform is expected to be independent of the WordPress theme. The audit therefore distinguishes a partial WordPress license client from the separate central control plane; do not label licensing as wholly absent.

#### Confirmed WordPress-side implementation in `main`
- `src/License/LicenseManager.php` contains a client-side license manager with key format validation (`JUNC-...`), activate/deactivate/validate actions, domain binding, encrypted key/cache storage, a 24-hour cache TTL, a 7-day connectivity grace, expiry checks, feature-list checks, lockout reason/time, and calls to `https://license.junctionsystems.pk/api/validate`.
- The client sends action, license key, current domain, product identifier, version and an HMAC signature. It handles HTTP 403 as domain mismatch and 402 as expired. This proves a remote validation integration was attempted; it does not prove that the remote endpoint is live, owned, secure, complete, or backed by a central admin panel.
- `src/License/LicenseFeatures.php` contains a local slug list and hard-coded Basic/Standard/Enterprise feature maps.
- `src/License/LicenseAdminUI.php` provides WordPress admin activation/deactivation UI, expiry notices and feature display. It currently displays the full decrypted license key.
- `src/License/Exception/LicenseException.php` defines the base and four derived exception classes in one file.
- The WordPress theme bootstrap attempts to install a `pkstore_require_feature` action hook that resolves LicenseManager and calls `requireFeature()`.

#### Confirmed gaps/defects in the WordPress-side client
- `ThemeBootstrapper` constructor stores the service container as `$this->c`, but the license guard closure references `$this->container`. The guard will not resolve the manager as written.
- The license guard is registered as an action hook; the inspected source does not establish that every privileged feature entry point invokes it. Existing local `FeatureManager` checks remain a separate authorization path.
- `LicenseFeatures::forTier()` maps an unrecognized tier silently to Basic; malformed/unknown package data should fail closed.
- The local 24-hour cache and 7-day network-offline grace are connectivity behavior only. They are not the owner-confirmed 7-day unpaid-invoice grace and must not be conflated.
- Cache is trusted locally during its TTL; a central suspension/downgrade may not reach the installation immediately. No signed entitlement revision, monotonic update, push/poll sync status or bounded reconciliation mechanism is evident in this client code.
- The remote response body is accepted as decoded JSON and cached without a verifiable server signature. The request HMAC uses a key stored in the same WordPress database; this does not establish secure server-to-client entitlement authenticity.
- Encryption uses AES-256-CBC without an authentication tag. The generated encryption key is stored in the same WordPress options database as encrypted values.
- The in-memory cache is returned before a fresh remote check; expiry/domain checks run locally, but current remote revocation/package changes are not fetched on every request.
- `deactivate()` ignores whether the remote response acknowledged deactivation, then deletes local key/cache/grace. This can leave central activation state and local state inconsistent if the remote call fails or reports failure without throwing.
- `isLockedOut()` reads a persistent lockout option, but the inspected manager does not provide a clear/reset workflow. The local guard defect and incomplete call-site coverage mean lockout is not proven to block every protected operation.
- WordPress admin activation/deactivation requires `manage_options`; this is a local site-admin capability, not proof of Junction platform-owner authorization.
- License UI exposes the decrypted full key and claims successful activation unlocks all features, while feature gating is not consistently authoritative across all routes/jobs/integrations.

#### Central platform status — not established by this repository
- The endpoint `license.junctionsystems.pk/api/validate` is referenced, but the server-side implementation, database, admin dashboard, customer/deployment registry, package editor, billing/subscription engine, invoice/payment verification, overdue scheduler, suspension/reactivation workflow, entitlement signing, deployment sync, and central audit/RBAC are not present in this repository tree.
- Do not conclude that no remote service exists solely from this repository. Its source may be in another private repository, hosting account or standalone application. It remains unverified until the owner supplies its repository or read-only API/specification/access.
- Architectural direction: keep the central licensing/billing service as a standalone application/API, independent of the WooCommerce theme. The WordPress theme should become a thin deployment agent/client that securely registers its immutable deployment ID, receives signed package entitlements, enforces them server-side, and reports health/sync state.
- Reuse the existing client only after fixing and testing it against the central service contract. Do not extend the theme into the central admin/billing control plane or assume the current endpoint implements the owner’s full requirements.


### Staff roles, permissions and deployment data boundaries — source audit (2026-11-16)

#### Confirmed implementation
- The reviewed application relies primarily on native WordPress/WooCommerce capabilities such as `manage_options`, `manage_woocommerce` and `edit_posts`.
- No custom cashier, stock clerk, store manager or deployment-specific employee role registration was found in the reviewed source paths.
- Admin operations menu is generally gated by `manage_woocommerce`; feature settings and branding use `manage_options`.
- POS REST routes share one permission callback: `manage_woocommerce OR edit_posts`. Inventory REST routes require `manage_woocommerce`.
- The POS controller records the current WordPress user as cashier when opening a session, but the inspected close-session, list-orders, current-session, sale and receipt methods do not consistently verify that the caller owns the referenced session or is assigned to its location.
- Inventory responses expose cost price, sale price, stock, supplier/warehouse-related operational data to every user who passes `manage_woocommerce`. No field-level role restriction is evident in these controllers.
- FeatureSettingsController lets any `manage_options` user change local feature toggles. This conflicts with the owner-confirmed rule that only Junction platform owner controls paid package entitlements; a local toggle must never grant a central entitlement.
- SecurityHardener removes selected WP user REST endpoints and restricts parts of `/wp/v2/` and `/wc/`, but this is not a substitute for per-route authorization. It explicitly exempts broad URI prefixes for COD, chat, webhooks and tracking, so each endpoint must enforce its own authorization/signature/ownership.
- Login throttling is keyed to `REMOTE_ADDR` and uses transient counters; proxy trust, atomicity and distributed deployment behavior are not established.

#### Security and business gaps
- A user with `edit_posts` may access POS endpoints under the current shared callback even if they are not a cashier or assigned to the store/location.
- POS close-session accepts a session ID and does not check that the current user owns that session or has manager override permission. Listing orders similarly does not prove session ownership.
- POS current-session accepts caller-supplied location ID and returns cashier/session details without checking location assignment.
- POS sale accepts caller-supplied session and location IDs; the use case checks session state but reviewed code does not bind it to current cashier or permitted location.
- Inventory has no visible location-scoped authorization: a user with `manage_woocommerce` can request stock for arbitrary location IDs and list all locations.
- The receipt WhatsApp endpoint accepts caller-supplied phone, order reference, item lines and total; it does not load and verify a persisted POS order before producing the message URL.
- Local feature toggles are controlled by WordPress administrators, not centrally synchronized entitlements. This is an authority-boundary violation, not just a UI issue.
- The review has not established cross-deployment data isolation at the hosting/database layer. The intended architecture is one isolated customer installation per deployment; verify unique DB credentials, backups, logs, API credentials, uploads and support access per installation.
- No audit log was established for employee permission changes, session overrides, price overrides, inventory adjustments, exports, sensitive customer-data access or credential changes.

#### Required role model for owner confirmation/design
- Platform owner: central-only authority for customer deployments, package entitlements, licenses, invoices, payments, suspension/reactivation, deployment health and centrally controlled integrations.
- Store owner: full authority within their own installation for store settings, employee accounts/roles, products, pricing, business policies and operations permitted by the purchased package; cannot change central entitlements or license status.
- Store manager: explicitly granted operational management, approvals and reports; no central licensing/billing authority.
- Cashier: POS sales and own/current shift operations; no arbitrary session closure, cost-price access, global stock adjustment, customer exports or employee administration unless explicitly granted.
- Inventory staff: assigned-location stock receipts/counts/transfers/adjustments; no payment gateway secrets, license controls, full customer exports or unrestricted POS session access.
- Support staff: limited customer support/order lookup and permitted human handoff; sensitive payment, API keys, licensing and financial exports masked or unavailable by default.
- Enforce both capability and resource ownership (deployment, location, session, order, employee assignment) server-side. Add auditable manager overrides and reason codes for sensitive actions.
- Role matrix and exact permissions remain owner-verification items; do not invent final business policy or add roles before confirming.



### Customer privacy, public endpoints and order-data exposure — source audit (2026-11-16)

#### COD OTP endpoints
- `/cod/send-otp` and `/cod/verify-otp` are public storefront routes protected only by a WordPress nonce. A public nonce is not proof that the caller owns the supplied order or phone number.
- Controller accepts caller-supplied `order_id` and `phone`; it does not load the order and compare the phone with the order billing phone, confirm that the order belongs to the current customer/session, or verify COD payment method/status before sending/verifying.
- Digital-only bypass trusts the supplied order ID and checks whether its products are virtual/downloadable; it does not prove order ownership or checkout-session binding.
- Successful OTP verification response claims the COD order is confirmed, but inspected controller only calls OTP use case; no durable order-level verified marker or WooCommerce checkout validation hook was established in this path.
- This is a potential order/phone enumeration and verification-binding vulnerability. Require signed checkout context, server-side order/customer/phone/method validation, rate limits by IP + phone + order + session, and persist a one-time verification proof bound to the eventual order.

#### AI chat endpoints and chat privacy
- Public `/chat` and `/chat/stream` routes use a public nonce and caller-provided `session_uuid`; rate limit is keyed only by that UUID. New UUIDs can bypass it.
- No customer authentication or order ownership is required for public product chat; that may be appropriate for anonymous shopping, but any future order lookup, personal recommendations based on customer profile, or human support handoff must add verified identity and order-level authorization.
- Streaming controller catches all throwables and returns only `[DONE]`; structured errors and operational visibility are missing.
- Review AI chat persistence for retention, deletion/export requests, access controls, prompt/provider logging, redaction and backups before claiming customer privacy compliance. No end-to-end privacy lifecycle is established by these controller files.

#### Affiliate account data
- Affiliate dashboard is restricted to logged-in users and loads data for the current user’s affiliate record, which is a positive ownership check.
- However, it returns the full affiliate payout account number in the API response along with commission, payout and order IDs. Mask payout destination by default; expose full value only to a narrowly authorized owner/admin workflow.
- Public affiliate product listing intentionally exposes approved product details and redirect URLs. Verify moderation and ensure private submitter/payment data never enters public DTOs.
- Affiliate account registration accepts payment account data; change/verification/audit controls need review.

#### POS and inventory data
- POS order list endpoint accepts a session ID and returns customer name, order total, payment method and order references without verifying that the caller owns the session or has an assigned location.
- WhatsApp receipt endpoint trusts caller-supplied phone, order reference, item descriptions and total instead of loading the persisted POS order; it can produce fabricated receipts/messages.
- Inventory endpoints return cost price, sale price and stock to any user with `manage_woocommerce`; no field-level separation or location assignment is implemented in the reviewed controller.
- These endpoints need resource-level authorization and DTO minimization, not only broad WordPress capabilities.

#### Tracking and courier webhooks
- Public tracking endpoint is nonce-protected but accepts any tracking number and returns courier status/events. A public nonce does not prove the requester owns the order. Consider order-number + email/phone verification or a high-entropy customer tracking token and per-IP throttling.
- Courier webhook routes are intentionally public and rely on provider signature verification. Invalid signatures, missing tracking numbers and unmatched orders all return HTTP 200 success, concealing failures and suppressing provider retries. Record security/processing outcomes and return provider-appropriate error codes where safe.
- Webhook handler lacks visible event-ID deduplication/replay protection and provider event ordering safeguards; a stale event may regress order status. Add durable event idempotency, timestamp/sequence checks and a legal status transition matrix.

#### Privacy lifecycle and customer-data governance
- In the reviewed tree, no clearly identifiable project-owned WordPress personal-data exporter/eraser integration, retention scheduler or customer-data deletion workflow was found. This is a source-tree finding, not a statement about WordPress/WooCommerce core capabilities or the operator's legal obligations.
- Define retention/deletion rules for OTP records, AI chat transcripts, affiliate payout details, POS customer details, webhook payload logs and exported configuration. Legal/tax/accounting retention exceptions must be owner/legal decisions.
- Ensure config exports/backups/logs exclude or protect customer personal data, payment destinations, license keys, API tokens and FBR credentials.
- For the separate-install deployment model, customer records must remain within that client's isolated database/storage/backup namespace. Support access must be explicitly authorized, time-bounded and audited.
- No formal breach-response, subject-access request, correction/export, consent record, data-processing agreement or subprocessor inventory was established in the reviewed source. These require product/operations policy and legal review; do not claim compliance based on code inspection.

