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

## Owner verification queue — platform-level AI administration and client settings

**Owner clarification recorded (16 Nov 2026):**

- AI platform-level configuration is controlled by the Junction Systems platform owner/vendor, not by the customer's store owner.
- The vendor sets up the customer's installation and controls AI enable/disable, the Groq API key and AI model configuration.
- The customer/store owner manages their own business-related settings within the installation, such as store information, product/business policies, support contacts/hours and other operational preferences exposed for client configuration.
- Store employees may access only the business/support functions explicitly granted to their role. They must not automatically receive access to platform-level AI configuration, provider credentials or licensing controls.
- Platform-level controls and secrets must be enforced server-side and must not be exposed in frontend responses, logs, chat history or client/staff views.
- Keep platform-owner controls distinct from client business settings in the admin UX, capability checks, documentation and support procedures.


## Owner verification queue — chat history and admin access

**Owner decision recorded (16 Nov 2026):**

- Store owners should be able to view customer chat history in the admin panel to investigate support issues and maintain service continuity.
- Chat-history access must be permission-controlled and limited to authorized store personnel; staff should not receive broader access than their role requires.
- The system must provide configurable data-retention controls and a documented deletion/expiry process consistent with applicable privacy requirements.
- Chat records should be protected in storage and transit, and access to customer conversations should be auditable. Avoid exposing unrelated customer conversations or sensitive data in admin views, exports or support handoffs.
- The customer-facing privacy notice should explain that chats may be stored and reviewed by authorized store support staff.

## Status

- Initial source inventory and first AI/product-context review completed.
- Owner-confirmed deployment model: one separately licensed, vendor-deployed installation per customer on vendor hosting; one store owner per installation at a time. This is not a shared multi-tenant SaaS deployment. AI feature decisions are recorded in the owner-verification sections above.
- Full feature-by-feature source review: pending.
- Runtime/integration/security tests: pending.
- Owner feature verification: pending.
- No production changes or merges performed as part of this audit.
